<?php

namespace Nisalatp\DynamicReportGenerator;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use ReflectionClass;
use ReflectionMethod;
use Nisalatp\DynamicReportGenerator\Types\ReportRequest;
use Nisalatp\DynamicReportGenerator\Types\ModelInfo;
use Nisalatp\DynamicReportGenerator\Types\ModelLink;
use Nisalatp\DynamicReportGenerator\Types\JoinPlan;
use Nisalatp\DynamicReportGenerator\Types\JoinStep;
use Nisalatp\DynamicReportGenerator\Types\FilterNode;
use Nisalatp\DynamicReportGenerator\Types\FilterGroup;
use Nisalatp\DynamicReportGenerator\Types\FilterLeaf;
use Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerException;

class ReportMaker
{
    private array $allowedModels = [];

    public function __construct(array $allowedModels)
    {
        foreach ($allowedModels as $modelClass) {
            if (class_exists($modelClass) && is_subclass_of($modelClass, Model::class)) {
                $this->allowedModels[$modelClass] = $this->getModelInfo($modelClass);
            }
        }
    }

    public function generate(ReportRequest $whatUserWants): Builder
    {
        $this->ensureModelAllowed($whatUserWants->baseModel);
        foreach ($whatUserWants->targetModels as $model) {
            $this->ensureModelAllowed($model);
        }

        $links = $this->discoverLinks();
        $joinPlan = $this->planJoins($whatUserWants->baseModel, $whatUserWants->targetModels, $links);

        $innerQuery = $this->buildInnerQuery(
            $whatUserWants->baseModel,
            $joinPlan,
            $whatUserWants->selectedAttributes,
            $whatUserWants->innerFilters
        );

        if (!empty($whatUserWants->groupBys) || !empty($whatUserWants->aggregates) || $whatUserWants->outerFilters !== null) {
            return $this->buildOuterQuery(
                $innerQuery,
                $whatUserWants->groupBys,
                $whatUserWants->aggregates,
                $whatUserWants->outerFilters
            );
        }

        return $innerQuery;
    }

    private function getModelInfo(string $modelClass): ModelInfo
    {
        /** @var Model $instance */
        $instance = new $modelClass();
        return new ModelInfo(
            modelClass: $modelClass,
            table: $instance->getTable(),
            primaryKey: $instance->getKeyName(),
            casts: $instance->getCasts()
        );
    }

    private function ensureModelAllowed(string $modelClass): void
    {
        if (!isset($this->allowedModels[$modelClass])) {
            throw ReportMakerException::modelNotAllowed($modelClass);
        }
    }

    private function discoverLinks(): array
    {
        $links = [];
        $supported = [
            'BelongsTo' => BelongsTo::class,
            'HasOne' => HasOne::class,
            'HasMany' => HasMany::class,
            'BelongsToMany' => BelongsToMany::class,
        ];

        foreach (array_keys($this->allowedModels) as $modelClass) {
            $reflection = new ReflectionClass($modelClass);
            $instance = new $modelClass();

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getNumberOfRequiredParameters() > 0 || $method->class !== $modelClass) continue;

                try {
                    $relation = $method->invoke($instance);
                    if ($relation instanceof Relation) {
                        $type = null;
                        foreach ($supported as $k => $c) {
                            if ($relation instanceof $c) { $type = $k; break; }
                        }
                        if (!$type) continue;

                        $toModel = get_class($relation->getRelated());
                        if (!isset($this->allowedModels[$toModel])) continue;

                        $foreignKey = ''; $localKey = '';
                        if ($relation instanceof BelongsTo) {
                            $foreignKey = $relation->getForeignKeyName();
                            $localKey = $relation->getOwnerKeyName();
                        } elseif ($relation instanceof HasOne || $relation instanceof HasMany) {
                            $foreignKey = $relation->getForeignKeyName();
                            $localKey = $relation->getLocalKeyName();
                        } elseif ($relation instanceof BelongsToMany) {
                            $foreignKey = $relation->getForeignPivotKeyName();
                            $localKey = $relation->getRelatedPivotKeyName();
                        }

                        if (!isset($links[$modelClass])) $links[$modelClass] = [];
                        $links[$modelClass][$toModel] = new ModelLink(
                            $modelClass, $toModel, $type, $foreignKey, $localKey, $method->getName()
                        );
                    }
                } catch (\Throwable $e) {}
            }
        }
        return $links;
    }

    private function planJoins(string $base, array $targets, array $links): JoinPlan
    {
        $steps = [];
        $aliasCounter = 1;

        foreach ($targets as $target) {
            if ($base === $target) continue;

            $path = $this->findShortestPath($base, $target, $links);
            if (!$path) throw ReportMakerException::noPath($base, $target);

            for ($i = 0; $i < count($path) - 1; $i++) {
                $from = $path[$i];
                $to = $path[$i+1];
                $link = $links[$from][$to];

                $steps[] = new JoinStep(
                    fromModel: $from,
                    toModel: $to,
                    joinType: 'left',
                    localTableAlias: $i === 0 ? 't0' : 't' . ($aliasCounter - 1),
                    remoteTableAlias: 't' . $aliasCounter,
                    localKey: $link->localKey,
                    foreignKey: $link->foreignKey,
                    relationType: $link->type
                );
                $aliasCounter++;
            }
        }
        return new JoinPlan($steps);
    }

    private function findShortestPath(string $start, string $end, array $links): ?array
    {
        $queue = [[$start]];
        $visited = [$start => true];

        while (!empty($queue)) {
            $path = array_shift($queue);
            $current = end($path);

            if ($current === $end) return $path;

            $neighbors = $links[$current] ?? [];
            foreach ($neighbors as $neighborClass => $link) {
                if (!isset($visited[$neighborClass])) {
                    $visited[$neighborClass] = true;
                    $newPath = $path;
                    $newPath[] = $neighborClass;
                    $queue[] = $newPath;
                }
            }
        }
        return null;
    }

    private function buildInnerQuery(string $base, JoinPlan $plan, array $selects, ?FilterNode $filters): Builder
    {
        $baseInstance = new $base();
        $query = DB::table($baseInstance->getTable() . ' as t0');
        
        foreach ($plan->steps as $step) {
            $toInstance = new $step->toModel();
            $first = $step->localTableAlias . '.' . $step->localKey;
            $second = $step->remoteTableAlias . '.' . $step->foreignKey;
            
            if ($step->relationType === 'BelongsTo') {
                $first = $step->localTableAlias . '.' . $step->foreignKey;
                $second = $step->remoteTableAlias . '.' . $step->localKey;
            }

            $query->join($toInstance->getTable() . ' as ' . $step->remoteTableAlias, $first, '=', $second, $step->joinType);
        }

        $aliases = [$base => 't0'];
        foreach ($plan->steps as $step) {
            $aliases[$step->toModel] = $step->remoteTableAlias;
        }

        $selectColumns = [];
        foreach ($selects as $attr) {
            $alias = $aliases[$attr->modelClass] ?? 't0';
            $selectColumns[] = $alias . '.' . $attr->column;
        }

        $query->select(empty($selectColumns) ? ['t0.*'] : $selectColumns);

        if ($filters) {
            $this->applyFilters($query, $filters, $aliases, 'where');
        }

        return $query;
    }

    private function buildOuterQuery(Builder $inner, array $groups, array $aggs, ?FilterNode $filters): Builder
    {
        $query = DB::table($inner, 'inner_query');
        $selects = [];
        $groupCols = [];

        foreach ($groups as $g) {
            $col = 'inner_query.' . $g->attribute->column;
            $selects[] = $col;
            $groupCols[] = $col;
        }

        foreach ($aggs as $a) {
            $func = strtoupper($a->function);
            $innerCol = 'inner_query.' . $a->attribute->column;
            $alias = $a->alias ?? strtolower($func . '_' . $a->attribute->column);
            $selects[] = DB::raw("{$func}({$innerCol}) as {$alias}");
        }

        $query->select(empty($selects) ? ['inner_query.*'] : $selects);
        if (!empty($groupCols)) $query->groupBy($groupCols);

        if ($filters) {
            $this->applyFilters($query, $filters, [], 'having');
        }

        return $query;
    }

    private function applyFilters(Builder $query, FilterNode $node, array $aliases, string $type, string $bool = 'and'): void
    {
        if ($node instanceof FilterGroup) {
            $method = $type === 'where' ? 'where' : 'havingNested';
            $query->$method(function ($sub) use ($node, $aliases, $type) {
                foreach ($node->children as $child) {
                    $this->applyFilters($sub, $child, $aliases, $type, $node->logic);
                }
            }, $type === 'where' ? null : $bool, null, $type === 'where' ? $bool : null);
            return;
        }

        if ($node instanceof FilterLeaf) {
            $col = $type === 'where' 
                ? ($aliases[$node->attribute->modelClass] ?? 't0') . '.' . $node->attribute->column 
                : $node->attribute->column;

            $methodMap = [
                'where' => ['null' => 'whereNull', 'notnull' => 'whereNotNull', 'in' => 'whereIn', 'between' => 'whereBetween', 'default' => 'where'],
                'having' => ['null' => 'havingNull', 'notnull' => 'havingNotNull', 'in' => 'having', /* havingIn doesn't exist natively like whereIn, wait.. having() handles it sometimes, but we can just use havingRaw or assume it's simple */ 'between' => 'havingBetween', 'default' => 'having']
            ];

            $map = $methodMap[$type];

            if ($node->operator === 'is null') {
                $query->{$map['null']}($col, $bool);
            } elseif ($node->operator === 'is not null') {
                $query->{$map['notnull']}($col, $bool);
            } elseif ($node->operator === 'in') {
                if ($type === 'where') $query->{$map['in']}($col, $node->value, $bool);
                else $query->havingRaw("$col in (" . implode(',', array_map(fn($v) => "'$v'", $node->value)) . ")", [], $bool);
            } elseif ($node->operator === 'between') {
                $query->{$map['between']}($col, $node->value, $bool);
            } else {
                $query->{$map['default']}($col, $node->operator, $node->value, $bool);
            }
        }
    }
}
