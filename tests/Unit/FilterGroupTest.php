<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Unit;

use Nisalatp\DynamicReportGenerator\Types\Attribute;
use Nisalatp\DynamicReportGenerator\Types\FilterGroup;
use Nisalatp\DynamicReportGenerator\Types\FilterLeaf;
use PHPUnit\Framework\TestCase;

class FilterGroupTest extends TestCase
{
    public function test_logic_or_is_preserved(): void
    {
        $this->assertSame('or', (new FilterGroup('OR'))->logic);
    }

    public function test_unknown_logic_falls_back_to_and(): void
    {
        $this->assertSame('and', (new FilterGroup('xnor'))->logic);
        $this->assertSame('and', (new FilterGroup('AND'))->logic);
    }

    public function test_toArray_is_recursive(): void
    {
        $leaf = new FilterLeaf(new Attribute('App\\Models\\User', 'id', 'integer'), '=', 1);
        $group = new FilterGroup('or', [$leaf]);
        $arr = $group->toArray();

        $this->assertSame('group', $arr['type']);
        $this->assertSame('or', $arr['logic']);
        $this->assertCount(1, $arr['children']);
        $this->assertSame('leaf', $arr['children'][0]['type']);
    }
}
