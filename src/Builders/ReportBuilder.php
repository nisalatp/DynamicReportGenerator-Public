<?php

namespace Nisalatp\DynamicReportGenerator\Builders;

use Nisalatp\DynamicReportGenerator\Types\ReportRequest;
use Nisalatp\DynamicReportGenerator\Types\Attribute;
use Nisalatp\DynamicReportGenerator\Types\GroupBy;
use Nisalatp\DynamicReportGenerator\Types\Aggregate;

class ReportBuilder
{
    private string $baseModel;
    private array $targetModels = [];
    private array $selectedAttributes = [];
    private ?FilterBuilder $innerFiltersBuilder = null;
    private array $groupBys = [];
    private array $aggregates = [];
    private ?FilterBuilder $outerFiltersBuilder = null;

    private function __construct(string $baseModelClass)
    {
        $this->baseModel = $baseModelClass;
    }

    public static function forModel(string $baseModelClass): self
    {
        return new self($baseModelClass);
    }

    public function withTarget(string $targetModelClass): self
    {
        if (!in_array($targetModelClass, $this->targetModels)) {
            $this->targetModels[] = $targetModelClass;
        }
        return $this;
    }

    public function select(string $modelClass, string $column, string $type = 'string', bool $isVirtual = false, ?string $alias = null): self
    {
        $this->selectedAttributes[] = new Attribute($modelClass, $column, $type, $isVirtual, null, $alias);
        return $this;
    }

    public function groupBy(string $modelClass, string $column, string $type = 'string'): self
    {
        $this->groupBys[] = new GroupBy(new Attribute($modelClass, $column, $type));
        return $this;
    }

    public function aggregate(string $modelClass, string $column, string $type, string $function, ?string $alias = null): self
    {
        $this->aggregates[] = new Aggregate(new Attribute($modelClass, $column, $type), $function, $alias);
        return $this;
    }

    public function filter(\Closure $callback): self
    {
        if (!$this->innerFiltersBuilder) {
            $this->innerFiltersBuilder = new FilterBuilder();
        }
        $callback($this->innerFiltersBuilder);
        return $this;
    }

    public function having(\Closure $callback): self
    {
        if (!$this->outerFiltersBuilder) {
            $this->outerFiltersBuilder = new FilterBuilder();
        }
        $callback($this->outerFiltersBuilder);
        return $this;
    }

    public function build(): ReportRequest
    {
        return new ReportRequest(
            $this->baseModel,
            $this->targetModels,
            $this->selectedAttributes,
            $this->innerFiltersBuilder ? $this->innerFiltersBuilder->getNode() : null,
            $this->groupBys,
            $this->aggregates,
            $this->outerFiltersBuilder ? $this->outerFiltersBuilder->getNode() : null
        );
    }
}
