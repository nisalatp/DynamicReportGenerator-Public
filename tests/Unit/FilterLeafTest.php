<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Unit;

use Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerException;
use Nisalatp\DynamicReportGenerator\Types\Attribute;
use Nisalatp\DynamicReportGenerator\Types\FilterLeaf;
use PHPUnit\Framework\TestCase;

class FilterLeafTest extends TestCase
{
    private function attr(string $type = 'string'): Attribute
    {
        return new Attribute('App\\Models\\User', 'name', $type);
    }

    public function test_operator_is_trimmed_and_lowercased(): void
    {
        $leaf = new FilterLeaf($this->attr(), '  LIKE  ', 'a%');
        $this->assertSame('like', $leaf->operator);
    }

    public function test_rejects_unknown_operator(): void
    {
        $this->expectException(ReportMakerException::class);
        new FilterLeaf($this->attr(), '<>', 1); // '<>' is not whitelisted ('!=' is)
    }

    public function test_like_requires_string_or_text_type(): void
    {
        $this->expectException(ReportMakerException::class);
        new FilterLeaf($this->attr('integer'), 'like', '5%');
    }

    public function test_in_operator_requires_array_value(): void
    {
        $this->expectException(ReportMakerException::class);
        new FilterLeaf($this->attr(), 'in', 'not-an-array');
    }

    public function test_between_requires_exactly_two_values(): void
    {
        $this->expectException(ReportMakerException::class);
        new FilterLeaf($this->attr('integer'), 'between', [1]);
    }

    public function test_valid_between_is_accepted(): void
    {
        $leaf = new FilterLeaf($this->attr('integer'), 'between', [1, 10]);
        $this->assertSame('between', $leaf->operator);
        $this->assertSame([1, 10], $leaf->value);
    }

    public function test_toArray_shape(): void
    {
        $arr = (new FilterLeaf($this->attr(), '=', 'Alice'))->toArray();
        $this->assertSame('leaf', $arr['type']);
        $this->assertSame('=', $arr['operator']);
        $this->assertSame('Alice', $arr['value']);
        $this->assertSame('name', $arr['attribute']['column']);
    }
}
