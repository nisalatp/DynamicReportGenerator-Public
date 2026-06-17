<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Unit;

use Nisalatp\DynamicReportGenerator\Types\Attribute;
use PHPUnit\Framework\TestCase;

class AttributeTest extends TestCase
{
    public function test_defaults(): void
    {
        $attr = new Attribute('App\\Models\\User', 'name', 'string');
        $this->assertFalse($attr->isVirtual);
        $this->assertNull($attr->alias);
        $this->assertNull($attr->jsonPath);
    }

    public function test_toArray_shape(): void
    {
        $attr = new Attribute('App\\Models\\User', 'spent', 'integer', true, null, 'total_spent');
        $arr = $attr->toArray();

        $this->assertSame('App\\Models\\User', $arr['modelClass']);
        $this->assertSame('spent', $arr['column']);
        $this->assertSame('integer', $arr['type']);
        $this->assertTrue($arr['isVirtual']);
        $this->assertSame('total_spent', $arr['alias']);
    }
}
