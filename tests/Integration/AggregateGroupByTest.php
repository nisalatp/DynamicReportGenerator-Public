<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Integration;

use Nisalatp\DynamicReportGenerator\Builders\ReportBuilder;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\Order;
use Nisalatp\DynamicReportGenerator\Tests\TestCase;

class AggregateGroupByTest extends TestCase
{
    public function test_group_by_status_with_sum_wraps_inner_query(): void
    {
        $request = ReportBuilder::forModel(Order::class)
            ->select(Order::class, 'status', 'string')
            ->select(Order::class, 'amount', 'integer')
            ->groupBy(Order::class, 'status')
            ->aggregate(Order::class, 'amount', 'integer', 'SUM', 'total')
            ->build();

        $query = $this->maker()->generate($request);

        // Aggregation is performed by wrapping the inner query as a subquery.
        $this->assertStringContainsString('inner_query', $query->toSql());

        $totals = $query->get()->pluck('total', 'status');
        $this->assertSame(300, (int) $totals['paid']);    // 100 + 200
        $this->assertSame(50, (int) $totals['pending']);   // 50
    }

    public function test_having_filters_aggregated_groups(): void
    {
        $request = ReportBuilder::forModel(Order::class)
            ->select(Order::class, 'status', 'string')
            ->select(Order::class, 'amount', 'integer')
            ->groupBy(Order::class, 'status')
            ->aggregate(Order::class, 'amount', 'integer', 'SUM', 'total')
            ->having(fn ($f) => $f->where(Order::class, 'total', '>', 100, 'integer'))
            ->build();

        $query = $this->maker()->generate($request);
        $this->assertStringContainsString('having', strtolower($query->toSql()));

        $statuses = $query->get()->pluck('status')->all();
        $this->assertContains('paid', $statuses);     // 300 > 100
        $this->assertNotContains('pending', $statuses); // 50 is filtered out
    }
}
