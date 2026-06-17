<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Unit;

use InvalidArgumentException;
use Nisalatp\DynamicReportGenerator\Types\Attribute;
use Nisalatp\DynamicReportGenerator\Types\Sort;
use PHPUnit\Framework\TestCase;

class SortTest extends TestCase
{
    private function attr(): Attribute
    {
        return new Attribute('App\\Models\\User', 'name', 'string');
    }

    public function test_accepts_asc_and_desc(): void
    {
        $this->assertSame('ASC', (new Sort($this->attr(), 'ASC'))->toArray()['direction']);
        $this->assertSame('DESC', (new Sort($this->attr(), 'DESC'))->toArray()['direction']);
    }

    public function test_direction_is_case_insensitive_and_normalised(): void
    {
        $this->assertSame('DESC', (new Sort($this->attr(), 'desc'))->toArray()['direction']);
    }

    public function test_defaults_to_asc(): void
    {
        $this->assertSame('ASC', (new Sort($this->attr()))->toArray()['direction']);
    }

    public function test_rejects_invalid_direction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Sort($this->attr(), 'SIDEWAYS');
    }
}
