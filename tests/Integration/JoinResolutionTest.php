<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Integration;

use Nisalatp\DynamicReportGenerator\Builders\ReportBuilder;
use Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerException;
use Nisalatp\DynamicReportGenerator\Registry\VirtualAttributeRegistry;
use Nisalatp\DynamicReportGenerator\ReportMaker;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\Category;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\Order;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\OrderItem;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\Product;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\User;
use Nisalatp\DynamicReportGenerator\Tests\TestCase;

class JoinResolutionTest extends TestCase
{
    public function test_constructor_drops_invalid_or_non_model_classes(): void
    {
        $maker = new ReportMaker(
            ['App\\Does\\Not\\Exist', \stdClass::class, User::class],
            new VirtualAttributeRegistry()
        );

        $this->assertSame([User::class], $maker->getAvailableModels());
    }

    public function test_bfs_resolves_shortest_path_user_to_product(): void
    {
        $request = ReportBuilder::forModel(User::class)
            ->withTarget(Product::class)
            ->select(User::class, 'name', 'string')
            ->build();

        $plan = $this->maker()->explainJoinPlan($request);

        $this->assertCount(3, $plan->steps, 'User -> Order -> OrderItem -> Product is 3 hops');

        $toModels = array_map(fn ($s) => $s->toModel, $plan->steps);
        $this->assertSame([Order::class, OrderItem::class, Product::class], $toModels);

        // Alias progression and join type.
        $this->assertSame('t0', $plan->steps[0]->localTableAlias);
        $this->assertSame('t1', $plan->steps[0]->remoteTableAlias);
        $this->assertSame('t3', $plan->steps[2]->remoteTableAlias);
        foreach ($plan->steps as $step) {
            $this->assertSame('left', $step->joinType);
        }
    }

    public function test_unreachable_model_throws_no_path(): void
    {
        $request = ReportBuilder::forModel(User::class)
            ->withTarget(Category::class) // Category has no relationships
            ->select(User::class, 'name', 'string')
            ->build();

        $this->expectException(ReportMakerException::class);
        $this->maker()->explainJoinPlan($request);
    }

    public function test_bidirectional_relationship_does_not_loop(): void
    {
        // Order belongsTo User and User hasMany Order; the visited-set must
        // prevent infinite traversal. One hop is expected.
        $request = ReportBuilder::forModel(Order::class)
            ->withTarget(User::class)
            ->select(Order::class, 'amount', 'integer')
            ->build();

        $plan = $this->maker()->explainJoinPlan($request);

        $this->assertCount(1, $plan->steps);
        $this->assertSame(User::class, $plan->steps[0]->toModel);
    }

    public function test_self_target_produces_no_join_steps(): void
    {
        $request = ReportBuilder::forModel(User::class)
            ->withTarget(User::class)
            ->select(User::class, 'name', 'string')
            ->build();

        $this->assertCount(0, $this->maker()->explainJoinPlan($request)->steps);
    }

    public function test_relationships_are_discovered_via_reflection(): void
    {
        $relationships = $this->maker()->getModelRelationships(User::class);
        $this->assertArrayHasKey(Order::class, $relationships);
    }
}
