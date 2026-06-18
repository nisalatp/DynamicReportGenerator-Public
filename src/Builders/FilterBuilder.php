<?php

namespace Nisalatp\DynamicReportGenerator\Builders;

use Nisalatp\DynamicReportGenerator\Types\FilterGroup;
use Nisalatp\DynamicReportGenerator\Types\FilterLeaf;
use Nisalatp\DynamicReportGenerator\Types\FilterNode;
use Nisalatp\DynamicReportGenerator\Types\Attribute;

/**
 * Filter Builder - Composite Pattern Interface.
 *
 * This class provides a fluent interface for constructing complex, deeply nested
 * AND/OR filtering rules. It builds a hierarchical Abstract Syntax Tree (AST) using
 * the Composite design pattern, where FilterGroups contain arrays of FilterLeaf nodes
 * or other nested FilterGroups.
 */
class FilterBuilder
{
    private string $logic = 'and';
    private array $children = [];

    public function __construct(string $logic = 'and')
    {
        $this->logic = $logic;
    }

    /**
     * Add a standard WHERE clause (FilterLeaf) to the current logical group.
     *
     * @param string $modelClass The model the column belongs to.
     * @param string $column The physical or virtual column name.
     * @param string $operator The comparison operator (e.g., '=', '>', 'like').
     * @param mixed|null $value The value to compare against.
     * @param string $type The data type for casting (e.g., 'string', 'integer').
     * @param bool $isVirtual True if the column is a Virtual Attribute.
     * @return self
     */
    public function where(string $modelClass, string $column, string $operator, mixed $value = null, string $type = 'string', bool $isVirtual = false): self
    {
        // For standard operators like '=', '>', etc.
        if (func_num_args() === 3) {
            $value = $operator;
            $operator = '=';
        }

        $this->children[] = new FilterLeaf(
            new Attribute($modelClass, $column, $type, $isVirtual),
            $operator,
            $value
        );
        return $this;
    }

    /**
     * Add an OR WHERE clause.
     * Note: In this simple builder, this wraps the condition in an 'or' FilterGroup.
     */
    public function orWhere(string $modelClass, string $column, string $operator, mixed $value = null, string $type = 'string', bool $isVirtual = false): self
    {
        // To implement OR logic at the current level, we might need a sub-group if the parent is 'and'.
        // For a simple builder, we can just create an OR group with the new condition.
        if (func_num_args() === 3) {
            $value = $operator;
            $operator = '=';
        }

        $leaf = new FilterLeaf(
            new Attribute($modelClass, $column, $type, $isVirtual),
            $operator,
            $value
        );
        
        $this->children[] = new FilterGroup('or', [$leaf]);
        return $this;
    }

    /**
     * Add a WHERE IS NULL clause.
     */
    public function whereNull(string $modelClass, string $column, string $type = 'string', bool $isVirtual = false): self
    {
        return $this->where($modelClass, $column, 'is null', null, $type, $isVirtual);
    }

    /**
     * Add a WHERE IS NOT NULL clause.
     */
    public function whereNotNull(string $modelClass, string $column, string $type = 'string', bool $isVirtual = false): self
    {
        return $this->where($modelClass, $column, 'is not null', null, $type, $isVirtual);
    }

    public function whereIn(string $modelClass, string $column, array $values, string $type = 'string', bool $isVirtual = false): self
    {
        return $this->where($modelClass, $column, 'in', $values, $type, $isVirtual);
    }

    public function whereBetween(string $modelClass, string $column, array $values, string $type = 'string', bool $isVirtual = false): self
    {
        return $this->where($modelClass, $column, 'between', $values, $type, $isVirtual);
    }

    /**
     * Create a nested FilterGroup (parentheses in SQL).
     *
     * @param \Closure $callback Receives a new FilterBuilder instance.
     * @param string $logic 'and' or 'or'.
     * @return self
     */
    public function nested(\Closure $callback, string $logic = 'and'): self
    {
        $subBuilder = new self($logic);
        $callback($subBuilder);
        
        if ($subBuilder->hasConditions()) {
            $this->children[] = $subBuilder->getNode();
        }
        
        return $this;
    }

    public function orNested(\Closure $callback): self
    {
        return $this->nested($callback, 'or');
    }

    public function hasConditions(): bool
    {
        return !empty($this->children);
    }

    /**
     * Compile the Builder down to its immutable AST Node representation.
     *
     * @return FilterNode|null The compiled FilterGroup or FilterLeaf, or null if empty.
     */
    public function getNode(): ?FilterNode
    {
        if (empty($this->children)) {
            return null;
        }

        if (count($this->children) === 1 && $this->children[0] instanceof FilterLeaf) {
            // Optimization: return leaf if only one
            if ($this->logic === 'and') {
                return $this->children[0];
            }
        }

        return new FilterGroup($this->logic, $this->children);
    }
}
