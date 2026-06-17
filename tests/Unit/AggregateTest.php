<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Unit;

use InvalidArgumentException;
use Nisalatp\DynamicReportGenerator\Types\Aggregate;
use Nisalatp\DynamicReportGenerator\Types\Attribute;
use PHPUnit\Framework\TestCase;

class AggregateTest extends TestCase
{
    private function attr(): Attribute
    {
        return new Attribute('App\\Models\\Order', 'amount', 'integer');
    }

    public function test_accepts_all_whitelisted_functions(): void
    {
        foreach (Aggregate::ALLOWED_FUNCTIONS as $fn) {
            $agg = new Aggregate($this->attr(), $fn);
            $this->assertSame($fn, $agg->toArray()['function']);
        }
    }

    public function test_function_is_normalised_to_uppercase(): void
    {
        $agg = new Aggregate($this->attr(), 'sum');
        $this->assertSame('SUM', $agg->toArray()['function']);
    }

    public function test_rejects_unknown_function(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Aggregate($this->attr(), 'MEDIAN');
    }

    public function test_rejects_sql_injection_in_function_name(): void
    {
        // The whitelist is the primary injection defence for the (non-bindable)
        // aggregate function token interpolated into the SELECT clause.
        $this->expectException(InvalidArgumentException::class);
        new Aggregate($this->attr(), 'SUM(amount)); DROP TABLE users; --');
    }

    public function test_toArray_shape(): void
    {
        $agg = new Aggregate($this->attr(), 'COUNT', 'total');
        $arr = $agg->toArray();
        $this->assertSame('COUNT', $arr['function']);
        $this->assertSame('total', $arr['alias']);
        $this->assertSame('amount', $arr['attribute']['column']);
    }
}
