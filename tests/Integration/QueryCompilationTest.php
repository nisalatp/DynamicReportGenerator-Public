<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Integration;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nisalatp\DynamicReportGenerator\Builders\ReportBuilder;
use Nisalatp\DynamicReportGenerator\Types\Attribute;
use Nisalatp\DynamicReportGenerator\Types\ReportRequest;
use Nisalatp\DynamicReportGenerator\Types\Sort;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\Product;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\User;
use Nisalatp\DynamicReportGenerator\Tests\TestCase;

class QueryCompilationTest extends TestCase
{
    public function test_simple_select_returns_all_rows(): void
    {
        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->select(User::class, 'email', 'string')
            ->build();

        $rows = $this->maker()->generate($request)->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['Alice', 'Bob'], $rows->pluck('name')->all());
    }

    public function test_integer_filter_value_is_cast_and_applied(): void
    {
        // Value supplied as a string but declared integer -> cast before binding.
        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->filter(fn ($f) => $f->where(User::class, 'id', '=', '1', 'integer'))
            ->build();

        $rows = $this->maker()->generate($request)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows->first()->name);
    }

    public function test_where_in(): void
    {
        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->filter(fn ($f) => $f->whereIn(User::class, 'id', [1, 2], 'integer'))
            ->build();

        $this->assertCount(2, $this->maker()->generate($request)->get());
    }

    public function test_where_between_inclusive(): void
    {
        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->filter(fn ($f) => $f->whereBetween(User::class, 'id', [1, 1], 'integer'))
            ->build();

        $rows = $this->maker()->generate($request)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows->first()->name);
    }

    public function test_like_filter(): void
    {
        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->filter(fn ($f) => $f->where(User::class, 'name', 'like', '%lic%', 'string'))
            ->build();

        $rows = $this->maker()->generate($request)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows->first()->name);
    }

    public function test_is_null_filter(): void
    {
        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->filter(fn ($f) => $f->whereNull(User::class, 'email'))
            ->build();

        $rows = $this->maker()->generate($request)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Bob', $rows->first()->name);
    }

    public function test_join_select_pulls_columns_across_tables(): void
    {
        $request = ReportBuilder::forModel(User::class)
            ->withTarget(Product::class)
            ->select(User::class, 'name', 'string')
            ->select(Product::class, 'name', 'string', false, 'product_name')
            ->build();

        $rows = $this->maker()->generate($request)->get();

        // 3 order_items across the dataset -> 3 joined rows.
        $this->assertCount(3, $rows);
        $productNames = $rows->pluck('product_name')->all();
        $this->assertContains('Widget', $productNames);
        $this->assertContains('Gadget', $productNames);
    }

    public function test_nested_and_or_parenthesisation(): void
    {
        // id >= 1 AND (name = 'Alice' OR name = 'Bob') -> both users.
        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->filter(function ($f) {
                $f->where(User::class, 'id', '>=', 1, 'integer')
                  ->nested(function ($g) {
                      $g->where(User::class, 'name', '=', 'Alice')
                        ->where(User::class, 'name', '=', 'Bob');
                  }, 'or');
            })
            ->build();

        $this->assertCount(2, $this->maker()->generate($request)->get());
    }

    public function test_sorting_descending(): void
    {
        // ReportBuilder has no sort helper, so build the request directly.
        $request = new ReportRequest(
            baseModel: User::class,
            selectedAttributes: [
                new Attribute(User::class, 'name', 'string'),
                new Attribute(User::class, 'id', 'integer'),
            ],
            sorts: [new Sort(new Attribute(User::class, 'id', 'integer'), 'DESC')]
        );

        $rows = $this->maker()->generate($request)->get();
        $this->assertSame(2, (int) $rows->first()->id);
    }

    public function test_to_raw_sql_interpolates_bindings(): void
    {
        $maker = $this->maker();

        $numeric = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->filter(fn ($f) => $f->where(User::class, 'id', '=', 1, 'integer'))
            ->build();
        $this->assertStringContainsString('= 1', $maker->toRawSql($maker->generate($numeric)));

        $string = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->filter(fn ($f) => $f->where(User::class, 'name', '=', 'Alice', 'string'))
            ->build();
        $this->assertStringContainsString("'Alice'", $maker->toRawSql($maker->generate($string)));
    }

    public function test_generate_paginated_returns_paginator(): void
    {
        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->build();

        $paginator = $this->maker()->generatePaginated($request, 1);

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertSame(1, $paginator->perPage());
        $this->assertSame(2, $paginator->total());
    }
}
