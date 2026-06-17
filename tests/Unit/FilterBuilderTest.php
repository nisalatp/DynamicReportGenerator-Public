<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Unit;

use Nisalatp\DynamicReportGenerator\Builders\FilterBuilder;
use Nisalatp\DynamicReportGenerator\Types\FilterGroup;
use Nisalatp\DynamicReportGenerator\Types\FilterLeaf;
use PHPUnit\Framework\TestCase;

class FilterBuilderTest extends TestCase
{
    public function test_three_argument_where_defaults_operator_to_equals(): void
    {
        $node = (new FilterBuilder())
            ->where('App\\Models\\User', 'id', 1)
            ->getNode();

        $this->assertInstanceOf(FilterLeaf::class, $node);
        $this->assertSame('=', $node->operator);
        $this->assertSame(1, $node->value);
    }

    public function test_explicit_operator(): void
    {
        $node = (new FilterBuilder())
            ->where('App\\Models\\User', 'age', '>', 18, 'integer')
            ->getNode();

        $this->assertInstanceOf(FilterLeaf::class, $node);
        $this->assertSame('>', $node->operator);
    }

    public function test_convenience_methods(): void
    {
        $this->assertSame('in', (new FilterBuilder())->whereIn('App\\Models\\User', 'id', [1, 2], 'integer')->getNode()->operator);
        $this->assertSame('is null', (new FilterBuilder())->whereNull('App\\Models\\User', 'email')->getNode()->operator);
        $this->assertSame('is not null', (new FilterBuilder())->whereNotNull('App\\Models\\User', 'email')->getNode()->operator);
        $this->assertSame('between', (new FilterBuilder())->whereBetween('App\\Models\\User', 'id', [1, 9], 'integer')->getNode()->operator);
    }

    public function test_empty_builder_returns_null_node(): void
    {
        $this->assertNull((new FilterBuilder())->getNode());
        $this->assertFalse((new FilterBuilder())->hasConditions());
    }

    public function test_single_or_leaf_returns_group(): void
    {
        // The single-leaf optimisation only collapses to a bare leaf when logic is 'and'.
        $node = (new FilterBuilder('or'))
            ->where('App\\Models\\User', 'id', 1)
            ->getNode();

        $this->assertInstanceOf(FilterGroup::class, $node);
        $this->assertSame('or', $node->logic);
    }

    public function test_nested_group_is_appended(): void
    {
        $node = (new FilterBuilder())
            ->where('App\\Models\\User', 'id', '>=', 1, 'integer')
            ->nested(function (FilterBuilder $g) {
                $g->where('App\\Models\\User', 'name', '=', 'Alice')
                  ->where('App\\Models\\User', 'name', '=', 'Bob');
            }, 'or')
            ->getNode();

        $this->assertInstanceOf(FilterGroup::class, $node);
        $this->assertSame('and', $node->logic);
        $this->assertCount(2, $node->children);
        $this->assertInstanceOf(FilterGroup::class, $node->children[1]);
        $this->assertSame('or', $node->children[1]->logic);
    }
}
