<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Unit;

use Nisalatp\DynamicReportGenerator\Builders\FilterBuilder;
use Nisalatp\DynamicReportGenerator\Builders\ReportBuilder;
use Nisalatp\DynamicReportGenerator\Types\ReportRequest;
use PHPUnit\Framework\TestCase;

class ReportBuilderTest extends TestCase
{
    public function test_builds_a_report_request(): void
    {
        $req = ReportBuilder::forModel('App\\Models\\User')
            ->withTarget('App\\Models\\Order')
            ->select('App\\Models\\User', 'name', 'string')
            ->build();

        $this->assertInstanceOf(ReportRequest::class, $req);
        $this->assertSame('App\\Models\\User', $req->baseModel);
        $this->assertSame(['App\\Models\\Order'], $req->targetModels);
        $this->assertCount(1, $req->selectedAttributes);
        $this->assertSame('name', $req->selectedAttributes[0]->column);
    }

    public function test_with_target_is_deduplicated(): void
    {
        $req = ReportBuilder::forModel('App\\Models\\User')
            ->withTarget('App\\Models\\Order')
            ->withTarget('App\\Models\\Order')
            ->build();

        $this->assertSame(['App\\Models\\Order'], $req->targetModels);
    }

    public function test_group_by_and_aggregate(): void
    {
        $req = ReportBuilder::forModel('App\\Models\\Order')
            ->select('App\\Models\\Order', 'status', 'string')
            ->groupBy('App\\Models\\Order', 'status')
            ->aggregate('App\\Models\\Order', 'amount', 'integer', 'SUM', 'total')
            ->build();

        $this->assertCount(1, $req->groupBys);
        $this->assertCount(1, $req->aggregates);
        $this->assertSame('SUM', $req->aggregates[0]->toArray()['function']);
    }

    public function test_filter_and_having_closures_populate_nodes(): void
    {
        $req = ReportBuilder::forModel('App\\Models\\Order')
            ->select('App\\Models\\Order', 'status', 'string')
            ->filter(fn (FilterBuilder $f) => $f->where('App\\Models\\Order', 'amount', '>', 100, 'integer'))
            ->having(fn (FilterBuilder $f) => $f->where('App\\Models\\Order', 'status', '=', 'paid'))
            ->build();

        $this->assertNotNull($req->innerFilters);
        $this->assertNotNull($req->outerFilters);
    }
}
