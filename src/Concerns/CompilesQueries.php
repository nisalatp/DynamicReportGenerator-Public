<?php

namespace Nisalatp\DynamicReportGenerator\Concerns;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
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
            if ($step->relationType === 'BelongsToMany_Pivot') {
                $pivotTable = $this->resolvePivotTable($step->fromModel, $step->toModel);
                $query->join(
                    $pivotTable . ' as ' . $step->remoteTableAlias,
                    $step->localTableAlias . '.' . $step->localKey,
                    '=',
                    $step->remoteTableAlias . '.' . $step->foreignKey,
                    'inner'
                );
                continue;
            }

            if ($step->relationType === 'BelongsToMany') {
                $toInstance = new $step->toModel();
                $aliases[$step->toModel] = $step->remoteTableAlias;
                $query->join(
                    $toInstance->getTable() . ' as ' . $step->remoteTableAlias,
                    $step->localTableAlias . '.' . $step->localKey,
                    '=',
                    $step->remoteTableAlias . '.' . $step->foreignKey,
                    'inner'
                );
                continue;
            }

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
        // We need to track pivot aliases for BelongsToMany so that the second step
        // (pivot → related) can reference the pivot table by its alias.
        $pivotAliasMap = [];  // remoteTableAlias of pivot step → actual pivot table name

        foreach ($plan->steps as $step) {
            if ($step->relationType === 'BelongsToMany_Pivot') {
                // First leg of a many-to-many: base_table JOIN pivot_table
                // The pivot table name was stored in the JoinPlan steps metadata.
                // We recover it by finding the matching __pivot__ entry from planJoins.
                // Since we can't store extra metadata in the readonly JoinStep, we
                // resolve it by looking up the pivot table directly from the Eloquent
                // relationship on the source model at build time.
                $pivotTable = $this->resolvePivotTable($step->fromModel, $step->toModel);

                $localKeyPath  = $step->localTableAlias  . '.' . $step->localKey;    // base.id
                $foreignKeyPath = $step->remoteTableAlias . '.' . $step->foreignKey;  // pivot.user_id

                $query->join(
                    $pivotTable . ' as ' . $step->remoteTableAlias,
                    $localKeyPath,
                    '=',
                    $foreignKeyPath,
                    $step->joinType
                );

                // Remember that this alias points to the pivot table, not a model table.
                $pivotAliasMap[$step->remoteTableAlias] = $pivotTable;
                continue;
            }

            if ($step->relationType === 'BelongsToMany') {
                // Second leg: pivot_table JOIN related_table
                $toInstance = new $step->toModel();

                $localKeyPath  = $step->localTableAlias  . '.' . $step->localKey;    // pivot.role_id
                $foreignKeyPath = $step->remoteTableAlias . '.' . $step->foreignKey; // related.id

                $query->join(
                    $toInstance->getTable() . ' as ' . $step->remoteTableAlias,
                    $localKeyPath,
                    '=',
                    $foreignKeyPath,
                    $step->joinType
                );
                continue;
            }

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
        // Only the final (related) step of a BelongsToMany pair maps the model class to an alias;
        // the pivot step is internal and has no model class to map.
        $aliases = [$base => 't0'];
        foreach ($plan->steps as $step) {
            if ($step->relationType !== 'BelongsToMany_Pivot') {
                $aliases[$step->toModel] = $step->remoteTableAlias;
            }
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

        // Build an alias map for the outer query context.
        // In the outer query all columns come from `inner_query`, so every model
        // maps to that alias. This ensures Virtual Attribute {THIS} placeholders
        // are replaced with 'inner_query' instead of the invalid fallback 't0'
        // which does not exist in the outer scope.
        $outerAliases = [$base => 'inner_query'];
        foreach ($innerSelects as $selAttr) {
            if (!isset($outerAliases[$selAttr->modelClass])) {
                $outerAliases[$selAttr->modelClass] = 'inner_query';
            }
        }

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
            $rawAliasName = !empty($aggregate->alias) ? $aggregate->alias : strtolower($aggregateFunction . '_' . preg_replace('/\s+/', '_', $innerColName));
            $aliasWrapped = $grammar->wrap($rawAliasName);

            $selects[] = DB::raw("{$aggregateFunction}({$innerColWrapped}) as {$aliasWrapped}");
        }

        $query->select(empty($selects) ? ['inner_query.*'] : $selects);
        if (!empty($groupCols))
            $query->groupBy($groupCols);

        if ($filters) {
            $this->applyFilters($query, $filters, $outerAliases, 'having', 'and', $base, $innerSelects, $aggs);
        }

        return $query;
    }

    /**
     * Recursively apply filters to a query using the Composite pattern.
     *
     * Handles FilterGroup (AND/OR nesting) and FilterLeaf (individual conditions)
     * for both WHERE and HAVING clause types.
     */
    private function applyFilters(Builder $query, FilterNode $node, array $aliases, string $type, string $bool = 'and', ?string $base = null, array $innerSelects = [], array $aggs = []): void
    {
        if ($node instanceof FilterGroup) {
            if (empty($node->children)) {
                return;
            }
            $method = $type === 'where' ? 'where' : 'havingNested';
            $query->$method(function ($sub) use ($node, $aliases, $type, $base, $innerSelects, $aggs) {
                foreach ($node->children as $child) {
                    $this->applyFilters($sub, $child, $aliases, $type, $node->logic, $base, $innerSelects, $aggs);
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
                if ($type === 'having') {
                    $col = $node->attribute->column;
                    $isAggNumeric = false;
                    foreach ($aggs as $agg) {
                        $innerAlias = $agg->attribute->column;
                        foreach ($innerSelects as $selAttr) {
                            if ($selAttr->modelClass === $agg->attribute->modelClass && $selAttr->column === $agg->attribute->column) {
                                $innerAlias = $selAttr->alias ?? $selAttr->column;
                                break;
                            }
                        }
                        
                        $func = strtoupper($agg->function);
                        $expectedAlias = !empty($agg->alias) ? $agg->alias : strtolower($func . '_' . preg_replace('/\s+/', '_', $innerAlias));
                        
                        if ($innerAlias === $node->attribute->column || $agg->attribute->column === $node->attribute->column || $expectedAlias === $node->attribute->column) {
                            $col = $expectedAlias;
                            $isAggNumeric = true;
                            break;
                        }
                    }
                } else {
                    $col = $type === 'where'
                        ? ($aliases[$node->attribute->modelClass] ?? 't0') . '.' . $node->attribute->column
                        : $node->attribute->column;
                }
            }

            $methodMap = [
                'where' => ['null' => 'whereNull', 'notnull' => 'whereNotNull', 'in' => 'whereIn', 'between' => 'whereBetween', 'default' => 'where'],
                'having' => ['null' => 'havingNull', 'notnull' => 'havingNotNull', 'in' => 'having', 'between' => 'havingBetween', 'default' => 'having']
            ];

            $map = $methodMap[$type];

            // Cast values strictly according to the attribute definition
            $value = $node->value;
            $attrType = $node->attribute->type;
            
            // Handle numeric casting for aggregate aliases from loosely typed inputs
            if (($isAggNumeric ?? false) && $attrType === 'string' && is_numeric($value)) {
                $attrType = 'float';
            }

            if ($value !== null) {
                switch ($attrType) {
                    case 'integer':
                        $value = is_array($value) ? array_map('intval', $value) : (int) $value;
                        break;
                    
                    case 'float':
                    case 'double':
                    case 'number':
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
                    $bindings = array_values((array) $value);
                    $placeholders = implode(',', array_fill(0, count($bindings), '?'));
                    // When $col is already a DB::raw() Expression (e.g. a VA subquery fragment)
                    // we must NOT wrap it with the grammar — wrapping would surround the entire
                    // SQL fragment in backticks, producing invalid SQL like `(SELECT SUM(...))`.
                    // For plain string column names, wrapping is correct and required.
                    $wrappedCol = $col instanceof Expression
                        ? (string) $col
                        : $query->getGrammar()->wrap((string) $col);
                    $query->havingRaw($wrappedCol . " in ($placeholders)", $bindings, $bool);
                }
            } elseif ($node->operator === 'between') {
                $query->{$map['between']}($col, (array) $value, $bool);
            } else {
                if ($type === 'having' && $value !== null && (is_float($value) || is_int($value))) {
                    // Bypass SQLite PDO string binding quirk where Integer < String is always true
                    $query->havingRaw($query->getGrammar()->wrap($col) . " {$node->operator} {$value}", [], $bool);
                } else {
                    $query->{$map['default']}($col, $node->operator, $value, $bool);
                }
            }
        }
    }

    /**
     * Resolve the pivot table name for a BelongsToMany relationship between two models.
     *
     * Because JoinStep is a readonly DTO that cannot carry arbitrary metadata, we
     * re-inspect the source model's relationships at JOIN-build time to find the
     * BelongsToMany that connects $fromModel → $toModel and return its pivot table.
     */
    private function resolvePivotTable(string $fromModel, string $toModel): string
    {
        $instance   = new $fromModel();
        $reflection = new \ReflectionClass($fromModel);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfRequiredParameters() > 0 || $method->class !== $fromModel) {
                continue;
            }
            try {
                $relation = $method->invoke($instance);
                if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
                    if (get_class($relation->getRelated()) === $toModel) {
                        return $relation->getTable();
                    }
                }
            } catch (\Throwable) {
            }
        }

        // Fallback: construct Laravel's conventional pivot table name (two singular model names,
        // alphabetically sorted, joined with an underscore — e.g. 'role_user').
        $parts = [
            strtolower(class_basename($fromModel)),
            strtolower(class_basename($toModel)),
        ];
        sort($parts);
        return implode('_', $parts);
    }
}
