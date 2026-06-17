<?php

namespace Nisalatp\DynamicReportGenerator\Builders;

use Nisalatp\DynamicReportGenerator\Types\FilterGroup;
use Nisalatp\DynamicReportGenerator\Types\FilterLeaf;
use Nisalatp\DynamicReportGenerator\Types\FilterNode;
use Nisalatp\DynamicReportGenerator\Types\Attribute;

class FilterBuilder
{
    private string $logic = 'and';
    private array $children = [];

    public function __construct(string $logic = 'and')
    {
        $this->logic = $logic;
    }

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

    public function whereNull(string $modelClass, string $column, string $type = 'string', bool $isVirtual = false): self
    {
        return $this->where($modelClass, $column, 'is null', null, $type, $isVirtual);
    }

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
