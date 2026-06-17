<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Unit;

use Error;
use Nisalatp\DynamicReportGenerator\Types\Attribute;
use Nisalatp\DynamicReportGenerator\Types\ReportRequest;
use PHPUnit\Framework\TestCase;

class ReportRequestTest extends TestCase
{
    public function test_defaults(): void
    {
        $req = new ReportRequest('App\\Models\\User');
        $this->assertSame([], $req->targetModels);
        $this->assertSame([], $req->selectedAttributes);
        $this->assertNull($req->innerFilters);
        $this->assertNull($req->outerFilters);
        $this->assertSame([], $req->sorts);
    }

    public function test_properties_are_immutable(): void
    {
        $req = new ReportRequest('App\\Models\\User');
        $this->expectException(Error::class);
        // @phpstan-ignore-next-line - intentionally violating readonly
        $req->baseModel = 'App\\Models\\Order';
    }

    public function test_json_round_trip_preserves_base_model_and_attributes(): void
    {
        $req = new ReportRequest(
            baseModel: 'App\\Models\\User',
            selectedAttributes: [new Attribute('App\\Models\\User', 'name', 'string')]
        );

        $restored = ReportRequest::fromJson($req->toJson());

        $this->assertSame('App\\Models\\User', $restored->baseModel);
        $this->assertCount(1, $restored->selectedAttributes);
        $this->assertSame('name', $restored->selectedAttributes[0]->column);
    }
}
