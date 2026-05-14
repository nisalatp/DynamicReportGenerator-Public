<?php

namespace Nisalatp\DynamicReportGenerator\Concerns;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Nisalatp\DynamicReportGenerator\Types\Attribute;
use Nisalatp\DynamicReportGenerator\Types\JoinPlan;
use Nisalatp\DynamicReportGenerator\Types\FilterNode;
use Nisalatp\DynamicReportGenerator\Types\FilterGroup;
use Nisalatp\DynamicReportGenerator\Types\FilterLeaf;
use Nisalatp\DynamicReportGenerator\Types\VirtualAttributeRequest;
use Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerException;

/**
 * Query Compilation — SQL construction for SELECT, JOIN, WHERE, GROUP BY, HAVING.
 *
 * Handles the inner/outer query pattern, filter application via the Composite
 * pattern, and Virtual Attribute subquery compilation.
 */
trait CompilesQueries
{
    /**
     * Compile a Virtual Attribute into a correlated scalar subquery string.
     */
    public function buildScalarSubquery(VirtualAttributeRequest $request): string
    {
        $this->ensureModelsLoaded();
        $this->ensureModelAllowed($request->baseModel);
        $this->ensureModelAllowed($request->targetModel);

        $links = $this->discoverLinks();

        // Find path from Target Model to Base Model using 'va_sub' as alias prefix
        $plan = $this->planJoins($request->targetModel, [$request->baseModel], $links, 'va_sub');

        $targetInstance = new $request->targetModel;

        $query = DB::table($targetInstance->getTable() . ' as va_sub0');

        $aliases = [$request->targetModel => 'va_sub0'];
        foreach ($plan->steps as $step) {
            $toInstance = new $step->toModel();

            $aliases[$step->fromModel] = $step->localTableAlias;
            $aliases[$step->toModel] = $step->remoteTableAlias;

            $localKeyPath = $step->localTableAlias . '.' . $step->localKey;
            $foreignKeyPath = $step->remoteTableAlias . '.' . $step->foreignKey;

            if ($step->relationType === 'BelongsTo') {
                $localKeyPath = $step->localTableAlias . '.' . $step->foreignKey;
                $foreignKeyPath = $step->remoteTableAlias . '.' . $step->localKey;
            }

            $query->join($toInstance->getTable() . ' as ' . $step->remoteTableAlias, $localKeyPath, '=', $foreignKeyPath, 'inner');
        }

        // Apply Correlation Binding
        $baseAliasInSubquery = $aliases[$request->baseModel] ?? 'va_sub0';
        $baseInstance = new $request->baseModel;
        $pk = $baseInstance->getKeyName();

        // Use whereRaw to bind to the outer query's t0 table
        $query->whereRaw("{$baseAliasInSubquery}.{$pk} = t0.{$pk}");

        // Select the aggregate
        $aggregateFunc = strtoupper($request->aggregateFunction);
        $aggregateCol = $request->aggregateColumn;
        $query->selectRaw("{$aggregateFunc}(va_sub0.{$aggregateCol})");

        // Apply Inner Filters
        if ($request->innerFilters) {
            $this->applyFilters($query, $request->innerFilters, $aliases, 'where', 'and', $request->targetModel);
        }

        // Apply Outer Filters (HAVING)
        if ($request->outerFilters) {
            $this->applyFilters($query, $request->outerFilters, $aliases, 'having', 'and', $request->targetModel);
        }

        return '(' . $this->toRawSql($query) . ')';
    }

    /**
     * Build the inner query: base SELECT with JOINs, column selection (with ALS interception),
     * Virtual Attribute injection, and WHERE filter application.
     */
    private function buildInnerQuery(string $base, JoinPlan $plan, array $selects, ?FilterNode $filters): Builder
    {
        // 1. Initialize Base Table
        $baseInstance = new $base();
        $query = DB::table($baseInstance->getTable() . ' as t0');

        // 2. Apply Relationship JOINs
        foreach ($plan->steps as $step) {
            $toInstance = new $step->toModel();
            
            $localKeyPath = $step->localTableAlias . '.' . $step->localKey;
            $foreignKeyPath = $step->remoteTableAlias . '.' . $step->foreignKey;

            // Eloquent's BelongsTo naturally inverts the key locations
            if ($step->relationType === 'BelongsTo') {
                $localKeyPath = $step->localTableAlias . '.' . $step->foreignKey;
                $foreignKeyPath = $step->remoteTableAlias . '.' . $step->localKey;
            }

            $query->join(
                $toInstance->getTable() . ' as ' . $step->remoteTableAlias, 
                $localKeyPath, 
                '=', 
                $foreignKeyPath, 
                $step->joinType
            );
        }

        // 3. Map Aliases for Column Resolution
        $aliases = [$base => 't0'];
        foreach ($plan->steps as $step) {
            $aliases[$step->toModel] = $step->remoteTableAlias;
        }

        // 4. Resolve SELECT Columns and Enforce Security
        $selectColumns = [];
        foreach ($selects as $attr) {
            $isVirtual = $attr->isVirtual || str_starts_with($attr->column, 'va:');
            $finalAlias = $attr->alias ?? $attr->column;

            // Apply Attribute-Level Security (ALS)
            $restriction = $this->getRestrictionType($attr->modelClass, $attr->column, $isVirtual);
            if ($restriction === 'blocked') {
                $selectColumns[] = DB::raw("'###' as " . $query->getGrammar()->wrap($finalAlias));
                continue;
            } elseif ($restriction === 'masked') {
                $selectColumns[] = DB::raw("'***' as " . $query->getGrammar()->wrap($finalAlias));
                continue;
            }

            // Handle Virtual Attribute Injection
            if ($isVirtual && $this->vaRegistry) {
                $name = str_starts_with($attr->column, 'va:') ? substr($attr->column, 3) : $attr->column;
                $va = $this->vaRegistry->findByName($attr->modelClass, $name);
                
                if ($va) {
                    $aliasPrefix = $aliases[$attr->modelClass] ?? 't0';
                    $fragment = str_replace('{THIS}', $aliasPrefix, $va->sql_fragment);
                    $selectColumns[] = DB::raw($fragment . ' as "' . $finalAlias . '"');
                    continue;
                } else {
                    throw new ReportMakerException("Virtual Attribute '{$name}' is missing or deleted. This query cannot be executed.");
                }
            }
            
            // Standard Physical Column
            $alias = $aliases[$attr->modelClass] ?? 't0';
            $selectColumns[] = $alias . '.' . $attr->column . ' as ' . $finalAlias;
        }

        $query->select(empty($selectColumns) ? ['t0.*'] : $selectColumns);

        // 5. Apply Inner Query Filters (WHERE clauses)
        if ($filters) {
            $this->applyFilters($query, $filters, $aliases, 'where', 'and', $base);
        }

        return $query;
    }

    /**
     * Build the outer query: wraps inner query for GROUP BY / aggregate / HAVING.
     */
    private function buildOuterQuery(string $base, Builder $inner, array $groups, array $aggs, ?FilterNode $filters, array $innerSelects = []): Builder
    {
        $query = DB::table($inner, 'inner_query');
        $selects = [];
        $groupCols = [];

        $getInnerAlias = function (Attribute $attr) use ($innerSelects) {
            foreach ($innerSelects as $selAttr) {
                if ($selAttr->modelClass === $attr->modelClass && $selAttr->column === $attr->column) {
                    return $selAttr->alias ?? $selAttr->column;
                }
            }
            return $attr->column;
        };

        $grammar = $query->getGrammar();

        foreach ($groups as $group) {
            $innerColName = $getInnerAlias($group->attribute);
            $colStr = 'inner_query.' . $innerColName;
            $selects[] = $colStr;
            $groupCols[] = $colStr;
        }

        foreach ($aggs as $aggregate) {
            $aggregateFunction = strtoupper($aggregate->function);
            $innerColName = $getInnerAlias($aggregate->attribute);

            $innerColWrapped = $grammar->wrap('inner_query.' . $innerColName);
            $rawAliasName = $aggregate->alias ?? strtolower($aggregateFunction . '_' . preg_replace('/\s+/', '_', $innerColName));
            $aliasWrapped = $grammar->wrap($rawAliasName);

            $selects[] = DB::raw("{$aggregateFunction}({$innerColWrapped}) as {$aliasWrapped}");
        }

        $query->select(empty($selects) ? ['inner_query.*'] : $selects);
        if (!empty($groupCols))
            $query->groupBy($groupCols);

        if ($filters) {
            $this->applyFilters($query, $filters, [], 'having', 'and', $base);
        }

        return $query;
    }

    /**
     * Recursively apply filters to a query using the Composite pattern.
     *
     * Handles FilterGroup (AND/OR nesting) and FilterLeaf (individual conditions)
     * for both WHERE and HAVING clause types.
     */
    private function applyFilters(Builder $query, FilterNode $node, array $aliases, string $type, string $bool = 'and', ?string $base = null): void
    {
        if ($node instanceof FilterGroup) {
            if (empty($node->children)) {
                return;
            }
            $method = $type === 'where' ? 'where' : 'havingNested';
            $query->$method(function ($sub) use ($node, $aliases, $type, $base) {
                foreach ($node->children as $child) {
                    $this->applyFilters($sub, $child, $aliases, $type, $node->logic, $base);
                }
            }, $type === 'where' ? null : $bool, null, $type === 'where' ? $bool : null);
            return;
        }

        if ($node instanceof FilterLeaf) {
            $isVirtual = $node->attribute->isVirtual || str_starts_with($node->attribute->column, 'va:');

            if ($isVirtual && $this->vaRegistry && $base) {
                $name = str_starts_with($node->attribute->column, 'va:') ? substr($node->attribute->column, 3) : $node->attribute->column;
                $va = $this->vaRegistry->findByName($node->attribute->modelClass, $name);
                if ($va) {
                    $aliasPrefix = $aliases[$node->attribute->modelClass] ?? 't0';
                    $fragment = str_replace('{THIS}', $aliasPrefix, $va->sql_fragment);
                    $col = DB::raw($fragment);
                } else {
                    $col = $type === 'where'
                        ? ($aliases[$node->attribute->modelClass] ?? 't0') . '.' . $node->attribute->column
                        : $node->attribute->column;
                }
            } else {
                $col = $type === 'where'
                    ? ($aliases[$node->attribute->modelClass] ?? 't0') . '.' . $node->attribute->column
                    : $node->attribute->column;
            }

            $methodMap = [
                'where' => ['null' => 'whereNull', 'notnull' => 'whereNotNull', 'in' => 'whereIn', 'between' => 'whereBetween', 'default' => 'where'],
                'having' => ['null' => 'havingNull', 'notnull' => 'havingNotNull', 'in' => 'having', 'between' => 'havingBetween', 'default' => 'having']
            ];

            $map = $methodMap[$type];

            // Cast values strictly according to the attribute definition
            $value = $node->value;
            if ($value !== null) {
                switch ($node->attribute->type) {
                    case 'integer':
                        $value = is_array($value) ? array_map('intval', $value) : (int) $value;
                        break;
                    
                    case 'float':
                    case 'double':
                        $value = is_array($value) ? array_map('floatval', $value) : (float) $value;
                        break;
                        
                    case 'boolean':
                        $value = is_array($value)
                            ? array_map(fn($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN), $value)
                            : filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        break;
                }
            }

            if ($node->operator === 'is null') {
                $query->{$map['null']}($col, $bool);
            } elseif ($node->operator === 'is not null') {
                $query->{$map['notnull']}($col, $bool);
            } elseif ($node->operator === 'in') {
                if ($type === 'where')
                    $query->{$map['in']}($col, $value, $bool);
                else {
                    $colStr = (string) $col;
                    $bindings = array_values((array) $value);
                    $placeholders = implode(',', array_fill(0, count($bindings), '?'));
                    $query->havingRaw("$colStr in ($placeholders)", $bindings, $bool);
                }
            } elseif ($node->operator === 'between') {
                $query->{$map['between']}($col, (array) $value, $bool);
            } else {
                $query->{$map['default']}($col, $node->operator, $value, $bool);
            }
        }
    }
}
