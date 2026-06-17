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
use Nisalatp\DynamicReportGenerator\Models\SavedReport;
use Nisalatp\DynamicReportGenerator\Models\ReportLog;
use Nisalatp\DynamicReportGenerator\Registry\VirtualAttributeRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportMaker
{
    private array $allowedModels = [];

    public function __construct(array $allowedModels, private ?VirtualAttributeRegistry $vaRegistry = null)
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
        
        $targetModels = $whatUserWants->targetModels;
        $this->extractVirtualAttributeDependencies($whatUserWants, $targetModels);
        
        foreach ($targetModels as $model) {
            $this->ensureModelAllowed($model);
        }

        $links = $this->discoverLinks();
        $joinPlan = $this->planJoins($whatUserWants->baseModel, $targetModels, $links);

        $innerQuery = $this->buildInnerQuery(
            $whatUserWants->baseModel,
            $joinPlan,
            $whatUserWants->selectedAttributes,
            $whatUserWants->innerFilters
        );

        if (!empty($whatUserWants->groupBys) || !empty($whatUserWants->aggregates) || $whatUserWants->outerFilters !== null) {
            $finalQuery = $this->buildOuterQuery(
                $whatUserWants->baseModel,
                $innerQuery,
                $whatUserWants->groupBys,
                $whatUserWants->aggregates,
                $whatUserWants->outerFilters,
                $whatUserWants->selectedAttributes
            );
        } else {
            $finalQuery = $innerQuery;
        }

        if (!empty($whatUserWants->sorts)) {
            foreach ($whatUserWants->sorts as $sort) {
                $colName = $sort->attribute->alias ?? $sort->attribute->column;
                if (str_starts_with($colName, 'va:')) {
                    $colName = substr($colName, 3);
                }
                $finalQuery->orderBy($colName, $sort->direction);
            }
        }

        return $finalQuery;
    }

    /**
     * Completeness: Generate a paginated report, protecting the host memory.
     */
    public function generatePaginated(ReportRequest $whatUserWants, int $perPage = 50): LengthAwarePaginator
    {
        return $this->generate($whatUserWants)->paginate($perPage);
    }

    /**
     * Ease of Use: Securely stream a report to CSV without memory exhaustion.
     */
    public function exportToCsv(ReportRequest $whatUserWants, string $filename = 'report.csv'): StreamedResponse
    {
        $query = $this->generate($whatUserWants);
        
        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            $headersWritten = false;

            $query->chunk(1000, function ($records) use ($handle, &$headersWritten) {
                foreach ($records as $record) {
                    $array = (array)$record;
                    if (!$headersWritten) {
                        fputcsv($handle, array_keys($array));
                        $headersWritten = true;
                    }
                    fputcsv($handle, array_values($array));
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    /**
     * Ease of Use: Convert a Builder query to a raw SQL string with bindings for debugging.
     */
    public function toRawSql(Builder $query): string
    {
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        foreach ($bindings as $binding) {
            $value = is_numeric($binding) ? $binding : "'" . addslashes($binding) . "'";
            $sql = preg_replace('/\?/', $value, $sql, 1);
        }

        return $sql;
    }

    /**
     * Completeness: Explain the Graph-Theory BFS Join Plan to the frontend.
     */
    public function explainJoinPlan(ReportRequest $whatUserWants): JoinPlan
    {
        $this->ensureModelAllowed($whatUserWants->baseModel);
        
        $targetModels = $whatUserWants->targetModels;
        $this->extractVirtualAttributeDependencies($whatUserWants, $targetModels);
        
        foreach ($targetModels as $model) {
            $this->ensureModelAllowed($model);
        }

        $links = $this->discoverLinks();
        return $this->planJoins($whatUserWants->baseModel, $targetModels, $links);
    }

    private function extractVirtualAttributeDependencies(ReportRequest $request, array &$targetModels): void
    {
        if (!$this->vaRegistry) return;

        $baseModel = $request->baseModel;

        foreach ($request->selectedAttributes as $attr) {
            if ($attr->isVirtual || str_starts_with($attr->column, 'va:')) {
                $name = str_starts_with($attr->column, 'va:') ? substr($attr->column, 3) : $attr->column;
                $va = $this->vaRegistry->findByName($baseModel, $name);
                if ($va && is_array($va->dependencies)) {
                    $targetModels = array_merge($targetModels, $va->dependencies);
                }
            }
        }

        $this->extractVAsFromFilter($request->innerFilters, $baseModel, $targetModels);
        $this->extractVAsFromFilter($request->outerFilters, $baseModel, $targetModels);

        $targetModels = array_unique($targetModels);
        $targetModels = array_values($targetModels);
    }

    private function extractVAsFromFilter(?FilterNode $node, string $baseModel, array &$targetModels): void
    {
        if (!$node || !$this->vaRegistry) return;

        if ($node instanceof FilterGroup) {
            foreach ($node->children as $child) {
                $this->extractVAsFromFilter($child, $baseModel, $targetModels);
            }
        } elseif ($node instanceof FilterLeaf) {
            if ($node->attribute->isVirtual || str_starts_with($node->attribute->column, 'va:')) {
                $name = str_starts_with($node->attribute->column, 'va:') ? substr($node->attribute->column, 3) : $node->attribute->column;
                $va = $this->vaRegistry->findByName($baseModel, $name);
                if ($va && is_array($va->dependencies)) {
                    $targetModels = array_merge($targetModels, $va->dependencies);
                }
            }
        }
    }

    private function logAction(?int $savedReportId, ?int $userId, string $action, ?array $details = null): void
    {
        ReportLog::create([
            'saved_report_id' => $savedReportId,
            'user_id' => $userId,
            'action' => $action,
            'details' => $details,
        ]);
    }

    public function saveReport(string $name, ReportRequest $request, ?int $userId = null, string $description = ''): SavedReport
    {
        try {
            $report = SavedReport::create([
                'name' => $name,
                'description' => $description,
                'payload' => json_decode($request->toJson(), true),
                'user_id' => $userId,
            ]);
            
            $this->logAction($report->id, $userId, 'created');
            return $report;
        } catch (\Throwable $e) {
            $this->logAction(null, $userId, 'error', ['operation' => 'saveReport', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getSavedReports(): Collection
    {
        return SavedReport::orderBy('created_at', 'desc')->get();
    }

    public function loadAndGenerate(int $savedReportId, ?int $executedByUserId = null): Builder
    {
        try {
            $savedReport = SavedReport::findOrFail($savedReportId);
            
            $json = is_string($savedReport->payload) 
                ? $savedReport->payload 
                : json_encode($savedReport->payload);

            $request = ReportRequest::fromJson($json);
            $builder = $this->generate($request);
            
            $this->logAction($savedReport->id, $executedByUserId, 'executed');
            
            return $builder;
        } catch (\Throwable $e) {
            $this->logAction($savedReportId, $executedByUserId, 'error', ['operation' => 'loadAndGenerate', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getReportLogs(int $savedReportId): Collection
    {
        return ReportLog::where('saved_report_id', $savedReportId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function assignReport(int $reportId, int $userId, ?int $actionByUserId = null): void
    {
        try {
            $report = SavedReport::findOrFail($reportId);
            $report->assignedUsers()->syncWithoutDetaching([$userId]);
            $this->logAction($reportId, $actionByUserId ?? $report->user_id, 'assigned', ['assigned_user_id' => $userId]);
        } catch (\Throwable $e) {
            $this->logAction($reportId, $actionByUserId, 'error', ['operation' => 'assignReport', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function unassignReport(int $reportId, int $userId, ?int $actionByUserId = null): void
    {
        try {
            $report = SavedReport::findOrFail($reportId);
            $report->assignedUsers()->detach($userId);
            $this->logAction($reportId, $actionByUserId ?? $report->user_id, 'unassigned', ['unassigned_user_id' => $userId]);
        } catch (\Throwable $e) {
            $this->logAction($reportId, $actionByUserId, 'error', ['operation' => 'unassignReport', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getAssignedReports(int $userId): Collection
    {
        // Fetch reports where the user is either the owner OR explicitly assigned
        return SavedReport::where('user_id', $userId)
            ->orWhereHas('assignedUsers', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function loadToEditor(int $reportId): ReportRequest
    {
        $savedReport = SavedReport::findOrFail($reportId);
        $json = is_string($savedReport->payload) 
            ? $savedReport->payload 
            : json_encode($savedReport->payload);

        return ReportRequest::fromJson($json);
    }

    public function updateReport(int $reportId, string $name, ReportRequest $request, string $description = '', ?int $actionByUserId = null): SavedReport
    {
        try {
            $report = SavedReport::findOrFail($reportId);
            $report->update([
                'name' => $name,
                'description' => $description,
                'payload' => json_decode($request->toJson(), true),
            ]);
            $this->logAction($reportId, $actionByUserId ?? $report->user_id, 'updated');
            return $report;
        } catch (\Throwable $e) {
            $this->logAction($reportId, $actionByUserId, 'error', ['operation' => 'updateReport', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function deleteReport(int $reportId, ?int $actionByUserId = null): void
    {
        try {
            $report = SavedReport::findOrFail($reportId);
            $report->delete();
            // Since report is deleted, saved_report_id in logs will become null due to "set null" constraint,
            // but we can still insert a log indicating the action.
            $this->logAction(null, $actionByUserId ?? $report->user_id, 'deleted', ['deleted_report_id' => $reportId]);
        } catch (\Throwable $e) {
            $this->logAction($reportId, $actionByUserId, 'error', ['operation' => 'deleteReport', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Schema Discovery: Get all available reportable models.
     *
     * @return array Array of allowed model class names.
     */
    public function getAvailableModels(): array
    {
        return array_keys($this->allowedModels);
    }

    /**
     * Schema Discovery: Get all attributes (physical and virtual) for a model.
     *
     * @param string $modelClass
     * @return array Array of attribute names.
     */
    public function getModelAttributes(string $modelClass): array
    {
        $this->ensureModelAllowed($modelClass);
        
        $table = $this->allowedModels[$modelClass]->table;
        $physicalCols = Schema::getColumnListing($table);

        $virtualCols = [];
        if ($this->vaRegistry) {
            $virtualAttrs = $this->vaRegistry->getForModel($modelClass);
            $virtualCols = $virtualAttrs->map(function($va) { return 'va:' . $va->name; })->toArray();
        }

        return array_merge($physicalCols, $virtualCols);
    }

    /**
     * Schema Discovery: Get all discoverable relationships for a model.
     *
     * @param string $modelClass
     * @return array Array of ModelLink objects detailing the relationships.
     */
    public function getModelRelationships(string $modelClass): array
    {
        $this->ensureModelAllowed($modelClass);
        
        $links = $this->discoverLinks();
        return $links[$modelClass] ?? [];
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
            $isVirtual = $attr->isVirtual || str_starts_with($attr->column, 'va:');
            $finalAlias = $attr->alias ?? $attr->column;
            
            if ($isVirtual && $this->vaRegistry) {
                $name = str_starts_with($attr->column, 'va:') ? substr($attr->column, 3) : $attr->column;
                $va = $this->vaRegistry->findByName($base, $name);
                if ($va) {
                    $selectColumns[] = DB::raw($va->sql_fragment . ' as "' . $finalAlias . '"');
                    continue;
                }
            }
            $alias = $aliases[$attr->modelClass] ?? 't0';
            $selectColumns[] = $alias . '.' . $attr->column . ' as ' . $finalAlias;
        }

        $query->select(empty($selectColumns) ? ['t0.*'] : $selectColumns);

        if ($filters) {
            $this->applyFilters($query, $filters, $aliases, 'where', 'and', $base);
        }

        return $query;
    }

    private function buildOuterQuery(string $base, Builder $inner, array $groups, array $aggs, ?FilterNode $filters, array $innerSelects = []): Builder
    {
        $query = DB::table($inner, 'inner_query');
        $selects = [];
        $groupCols = [];

        $getInnerAlias = function(\Nisalatp\DynamicReportGenerator\Types\Attribute $attr) use ($innerSelects) {
            foreach ($innerSelects as $selAttr) {
                if ($selAttr->modelClass === $attr->modelClass && $selAttr->column === $attr->column) {
                    return $selAttr->alias ?? $selAttr->column;
                }
            }
            return $attr->column;
        };

        $grammar = $query->getGrammar();

        foreach ($groups as $g) {
            $innerColName = $getInnerAlias($g->attribute);
            $colStr = 'inner_query.' . $innerColName;
            $selects[] = $colStr;
            $groupCols[] = $colStr;
        }

        foreach ($aggs as $a) {
            $func = strtoupper($a->function);
            $innerColName = $getInnerAlias($a->attribute);
            
            $innerColWrapped = $grammar->wrap('inner_query.' . $innerColName);
            $rawAliasName = $a->alias ?? strtolower($func . '_' . preg_replace('/\s+/', '_', $innerColName));
            $aliasWrapped = $grammar->wrap($rawAliasName);
            
            $selects[] = DB::raw("{$func}({$innerColWrapped}) as {$aliasWrapped}");
        }

        $query->select(empty($selects) ? ['inner_query.*'] : $selects);
        if (!empty($groupCols)) $query->groupBy($groupCols);

        if ($filters) {
            $this->applyFilters($query, $filters, [], 'having', 'and', $base);
        }

        return $query;
    }

    private function applyFilters(Builder $query, FilterNode $node, array $aliases, string $type, string $bool = 'and', ?string $base = null): void
    {
        if ($node instanceof FilterGroup) {
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
                $va = $this->vaRegistry->findByName($base, $name);
                if ($va) {
                    $col = DB::raw($va->sql_fragment);
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

            $value = $node->value;
            if ($value !== null) {
                if ($node->attribute->type === 'integer') {
                    $value = is_array($value) ? array_map('intval', $value) : (int) $value;
                } elseif ($node->attribute->type === 'float' || $node->attribute->type === 'double') {
                    $value = is_array($value) ? array_map('floatval', $value) : (float) $value;
                } elseif ($node->attribute->type === 'boolean') {
                    $value = is_array($value) 
                        ? array_map(fn($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN), $value) 
                        : filter_var($value, FILTER_VALIDATE_BOOLEAN);
                }
            }

            if ($node->operator === 'is null') {
                $query->{$map['null']}($col, $bool);
            } elseif ($node->operator === 'is not null') {
                $query->{$map['notnull']}($col, $bool);
            } elseif ($node->operator === 'in') {
                if ($type === 'where') $query->{$map['in']}($col, $value, $bool);
                else {
                    $colStr = (string) $col;
                    $query->havingRaw("$colStr in (" . implode(',', array_map(fn($v) => "'$v'", (array)$value)) . ")", [], $bool);
                }
            } elseif ($node->operator === 'between') {
                $query->{$map['between']}($col, (array)$value, $bool);
            } else {
                $query->{$map['default']}($col, $node->operator, $value, $bool);
            }
        }
    }
}
