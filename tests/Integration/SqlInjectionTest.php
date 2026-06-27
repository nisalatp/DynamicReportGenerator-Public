<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Integration;

use Illuminate\Support\Facades\Schema;
use Nisalatp\DynamicReportGenerator\Builders\ReportBuilder;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\User;
use Nisalatp\DynamicReportGenerator\Tests\TestCase;

class SqlInjectionTest extends TestCase
{
    private const PAYLOAD = "x'; DROP TABLE users; --";

    public function test_filter_value_is_parameter_bound_not_interpolated(): void
    {
        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->filter(fn ($f) => $f->where(User::class, 'name', '=', self::PAYLOAD, 'string'))
            ->build();

        $query = $this->maker()->generate($request, []);

        // The malicious string travels as a PDO binding, never as SQL text.
        $this->assertContains(self::PAYLOAD, $query->getBindings());
        $this->assertStringNotContainsString('DROP TABLE', $query->toSql());

        // Executing it must be harmless.
        $query->get();
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_where_in_values_are_parameter_bound(): void
    {
        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->filter(fn ($f) => $f->whereIn(User::class, 'name', [self::PAYLOAD, 'Alice'], 'string'))
            ->build();

        $query = $this->maker()->generate($request, []);

        $this->assertContains(self::PAYLOAD, $query->getBindings());
        $query->get();
        $this->assertTrue(Schema::hasTable('users'));
    }
}
