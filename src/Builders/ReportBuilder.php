<?php

namespace Nisalatp\DynamicReportGenerator\Builders;

use Nisalatp\DynamicReportGenerator\Types\ReportRequest;
use Nisalatp\DynamicReportGenerator\Types\Attribute;
use Nisalatp\DynamicReportGenerator\Types\GroupBy;
use Nisalatp\DynamicReportGenerator\Types\Aggregate;
use Nisalatp\DynamicReportGenerator\Types\Sort;

/**
 * Report Builder - Fluent API Interface.
 *
 * This class implements the Builder design pattern to construct complex
 * ReportRequest Abstract Syntax Trees (ASTs). It provides a clean, method-chaining
 * interface for controllers and tests to programmatically build reporting requirements.
 */
class ReportBuilder
{
    private string $baseModel;
    private array $targetModels = [];
    private array $selectedAttributes = [];
    private ?FilterBuilder $innerFiltersBuilder = null;
    private array $groupBys = [];
    private array $aggregates = [];
    private ?FilterBuilder $outerFiltersBuilder = null;
    private array $sorts = [];

    private function __construct(string $baseModelClass)
    {
        $this->baseModel = $baseModelClass;
    }

    /**
     * Start building a new report with the specified base (primary) model.
     *
     * @param string $baseModelClass Fully qualified class name.
     * @return self
     */
    public static function forModel(string $baseModelClass): self
    {
        return new self($baseModelClass);
    }

    /**
     * Explicitly declare a target model dependency.
     * Note: Models are also auto-discovered if used in select(), filter(), etc.
     *
     * @param string $targetModelClass Fully qualified class name.
     * @return self
     */
    public function withTarget(string $targetModelClass): self
    {
        if (!in_array($targetModelClass, $this->targetModels)) {
            $this->targetModels[] = $targetModelClass;
        }
        return $this;
    }

    /**
     * Add a column to the SELECT clause.
     *
     * @param string $modelClass The model the column belongs to.
     * @param string $column The physical or virtual column name.
     * @param string $type The data type (e.g., 'string', 'integer').
     * @param bool $isVirtual True if this is a Virtual Attribute.
     * @param string|null $alias Optional alias for the final result set.
     * @return self
     */
    public function select(string $modelClass, string $column, string $type = 'string', bool $isVirtual = false, ?string $alias = null): self
    {
        $this->selectedAttributes[] = new Attribute($modelClass, $column, $type, $isVirtual, null, $alias);
        return $this;
    }

    /**
     * Add a GROUP BY clause.
     */
    public function groupBy(string $modelClass, string $column, string $type = 'string'): self
    {
        $this->groupBys[] = new GroupBy(new Attribute($modelClass, $column, $type));
        return $this;
    }

    /**
     * Add an aggregate calculation (e.g., SUM, COUNT).
     */
    public function aggregate(string $modelClass, string $column, string $type, string $function, ?string $alias = null): self
    {
        $this->aggregates[] = new Aggregate(new Attribute($modelClass, $column, $type), $function, $alias);
        return $this;
    }

    /**
     * Define inner query filters (WHERE clause) using a closure callback.
     * The callback receives a FilterBuilder instance.
     */
    public function filter(\Closure $callback): self
    {
        if (!$this->innerFiltersBuilder) {
            $this->innerFiltersBuilder = new FilterBuilder();
        }
        $callback($this->innerFiltersBuilder);
        return $this;
    }

    /**
     * Add an ORDER BY clause.
     *
     * @param string $modelClass The model the column belongs to.
     * @param string $column     The column (or alias) to sort by.
     * @param string $type       The data type (e.g., 'string', 'integer').
     * @param string $direction  'ASC' or 'DESC'.
     * @return self
     */
    public function sort(string $modelClass, string $column, string $type = 'string', string $direction = 'ASC'): self
    {
        $this->sorts[] = new Sort(new Attribute($modelClass, $column, $type), $direction);
        return $this;
    }

    /**
     * Define outer query filters (HAVING clause) using a closure callback.
     */
    public function having(\Closure $callback): self
    {
        if (!$this->outerFiltersBuilder) {
            $this->outerFiltersBuilder = new FilterBuilder();
        }
        $callback($this->outerFiltersBuilder);
        return $this;
    }

    /**
     * Finalize and construct the immutable ReportRequest AST object.
     *
     * @return ReportRequest The fully compiled Abstract Syntax Tree.
     */
    public function build(): ReportRequest
    {
        // Construct the immutable DTO by resolving nested builders into Node trees
        return new ReportRequest(
            baseModel: $this->baseModel,
            targetModels: $this->targetModels,
            selectedAttributes: $this->selectedAttributes,
            innerFilters: $this->innerFiltersBuilder ? $this->innerFiltersBuilder->getNode() : null,
            groupBys: $this->groupBys,
            aggregates: $this->aggregates,
            outerFilters: $this->outerFiltersBuilder ? $this->outerFiltersBuilder->getNode() : null,
            sorts: $this->sorts
        );
    }
}
