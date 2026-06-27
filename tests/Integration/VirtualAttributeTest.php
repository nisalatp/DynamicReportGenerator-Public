<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Integration;

use Nisalatp\DynamicReportGenerator\Builders\ReportBuilder;
use Nisalatp\DynamicReportGenerator\Builders\VirtualAttributeBuilder;
use Nisalatp\DynamicReportGenerator\Models\VirtualAttribute;
use Nisalatp\DynamicReportGenerator\Registry\VirtualAttributeRegistry;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\Order;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\OrderItem;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\User;
use Nisalatp\DynamicReportGenerator\Tests\TestCase;

class VirtualAttributeTest extends TestCase
{
    /**
     * NOTE: the SQL fragment references the base table alias `t0`, because the
     * engine always aliases the base table as t0 (DB::table('users as t0')).
     */
    private function registerTotalSpent(): void
    {
        VirtualAttributeBuilder::create('Total Spent')
            ->forBaseModel(User::class)
            ->withSqlFragment('(SELECT COALESCE(SUM(orders.amount), 0) FROM orders WHERE orders.user_id = t0.id)')
            ->register();
    }

    public function test_register_is_idempotent(): void
    {
        $this->registerTotalSpent();
        $this->registerTotalSpent();

        $count = VirtualAttribute::where('base_model', User::class)
            ->where('name', 'Total Spent')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_registry_lookup(): void
    {
        $this->registerTotalSpent();
        $registry = new VirtualAttributeRegistry();

        $va = $registry->findByName(User::class, 'Total Spent');
        $this->assertNotNull($va);
        $this->assertStringContainsString('SUM', $va->sql_fragment);
        $this->assertCount(1, $registry->getForModel(User::class));
    }

    public function test_get_model_attributes_lists_physical_and_virtual(): void
    {
        $this->registerTotalSpent();
        $attributes = $this->maker()->getModelAttributes(User::class);

        $this->assertContains('name', $attributes);          // physical column
        $this->assertContains('va:Total Spent', $attributes); // virtual
    }

    public function test_virtual_attribute_is_injected_and_returns_scalar(): void
    {
        $this->registerTotalSpent();

        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->select(User::class, 'Total Spent', 'integer', true, 'total_spent')
            ->build();

        $totals = $this->maker()->generate($request, [])->get()->pluck('total_spent', 'name');

        $this->assertSame(150, (int) $totals['Alice']); // 100 + 50
        $this->assertSame(200, (int) $totals['Bob']);    // 200
    }

    public function test_declared_dependencies_are_injected_into_join_plan(): void
    {
        VirtualAttributeBuilder::create('Item Count')
            ->forBaseModel(User::class)
            ->dependsOn([Order::class, OrderItem::class])
            ->withSqlFragment('(SELECT COUNT(*) FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE o.user_id = t0.id)')
            ->register();

        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->select(User::class, 'Item Count', 'integer', true, 'item_count')
            ->build();

        $toModels = array_map(fn ($s) => $s->toModel, $this->maker()->explainJoinPlan($request)->steps);

        $this->assertContains(Order::class, $toModels);
        $this->assertContains(OrderItem::class, $toModels);
    }
}
