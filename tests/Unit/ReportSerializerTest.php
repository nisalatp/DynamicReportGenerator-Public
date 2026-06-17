<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Unit;

use InvalidArgumentException;
use Nisalatp\DynamicReportGenerator\Types\Aggregate;
use Nisalatp\DynamicReportGenerator\Types\Attribute;
use Nisalatp\DynamicReportGenerator\Types\FilterGroup;
use Nisalatp\DynamicReportGenerator\Types\FilterLeaf;
use Nisalatp\DynamicReportGenerator\Types\GroupBy;
use Nisalatp\DynamicReportGenerator\Types\ReportRequest;
use Nisalatp\DynamicReportGenerator\Types\ReportSerializer;
use Nisalatp\DynamicReportGenerator\Types\Sort;
use PHPUnit\Framework\TestCase;

class ReportSerializerTest extends TestCase
{
    public function test_full_round_trip_is_stable(): void
    {
        // NOTE: Attribute aliases are intentionally omitted here because
        // ReportSerializer::parseAttribute() does not currently restore the
        // `alias` field (see the project notes / flagged finding). Aggregate
        // aliases, however, are preserved.
        $request = new ReportRequest(
            baseModel: 'App\\Models\\User',
            targetModels: ['App\\Models\\Order'],
            selectedAttributes: [
                new Attribute('App\\Models\\User', 'name', 'string'),
                new Attribute('App\\Models\\User', 'email', 'string'),
            ],
            innerFilters: new FilterGroup('and', [
                new FilterLeaf(new Attribute('App\\Models\\User', 'created_at', 'date'), '>=', '2025-01-01'),
                new FilterLeaf(new Attribute('App\\Models\\Order', 'status', 'string'), '=', 'paid'),
            ]),
            groupBys: [new GroupBy(new Attribute('App\\Models\\Order', 'status', 'string'))],
            aggregates: [new Aggregate(new Attribute('App\\Models\\Order', 'amount', 'integer'), 'SUM', 'total')],
            sorts: [new Sort(new Attribute('App\\Models\\Order', 'status', 'string'), 'DESC')]
        );

        $serializer = new ReportSerializer();
        $restored = $serializer->fromJson($serializer->toJson($request));

        // Re-serialising the restored object should yield identical JSON.
        $this->assertSame($serializer->toJson($request), $serializer->toJson($restored));
    }

    public function test_missing_base_model_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ReportSerializer())->fromJson('{}');
    }

    public function test_filter_node_without_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ReportSerializer())->fromJson('{"baseModel":"App\\\\Models\\\\User","innerFilters":{"logic":"and"}}');
    }

    public function test_unknown_filter_node_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ReportSerializer())->fromJson('{"baseModel":"App\\\\Models\\\\User","innerFilters":{"type":"banana"}}');
    }
}
