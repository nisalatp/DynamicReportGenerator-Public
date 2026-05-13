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
use Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerSecurityException;
use Nisalatp\DynamicReportGenerator\Models\SavedReport;
use Nisalatp\DynamicReportGenerator\Models\ReportLog;
use Nisalatp\DynamicReportGenerator\Models\RestrictedModel;
use Nisalatp\DynamicReportGenerator\Models\AttributeRestriction;
use Nisalatp\DynamicReportGenerator\Registry\VirtualAttributeRegistry;
use Nisalatp\DynamicReportGenerator\Contracts\DynamicReportSubject;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\Finder\Finder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportMaker
{
    private ?array $allowedModels = null;
    private ?array $allApplicationModels = null;
    private ?array $restrictedModels = null;
    private array $resolvedRestrictions = [];

    /**
     * In-memory cache for the bidirectional link graph.
     * Computed once per ReportMaker instance to avoid repeated reflection overhead.
     */
    private ?array $cachedLinks = null;

    public function __construct(private ?VirtualAttributeRegistry $vaRegistry = null)
    {
        // Initialization is now lazy-loaded via ensureModelsLoaded()
    }

    private function ensureModelsLoaded(): void
    {
        if ($this->allowedModels !== null) {
            return;
        }

        $this->allowedModels = [];
        $allModels = $this->getAllApplicationModels();
        $restricted = $this->getRestrictedModels();

        foreach ($allModels as $modelClass) {
            if (!in_array($modelClass, $restricted)) {
                $this->allowedModels[$modelClass] = $this->getModelInfo($modelClass);
            }
        }
    }

    public function generate(ReportRequest $whatUserWants, ?array $subjects = null): Builder
    {
        $this->ensureModelsLoaded();
        $this->ensureModelAllowed($whatUserWants->baseModel);
        $this->resolveAttributeRestrictions($subjects);
        $this->validateSecurity($whatUserWants);
        
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
    public function generatePaginated(ReportRequest $whatUserWants, int $perPage = 50, ?array $subjects = null): LengthAwarePaginator
    {
        return $this->generate($whatUserWants, $subjects)->paginate($perPage);
    }

    /**
     * Ease of Use: Securely stream a report to CSV without memory exhaustion.
     */
    public function exportToCsv(ReportRequest $whatUserWants, string $filename = 'report.csv', ?array $subjects = null): StreamedResponse
    {
        $query = $this->generate($whatUserWants, $subjects);
        
        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            $headersWritten = false;

            foreach ($query->cursor() as $record) {
                $array = (array)$record;
                if (!$headersWritten) {
                    fputcsv($handle, array_keys($array));
                    $headersWritten = true;
                }
                fputcsv($handle, array_values($array));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    /**
     * Convert a Builder query to a raw SQL string with bindings for debugging.
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
     * Explain the Graph-Theory BFS Join Plan to the frontend.
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

    public function buildScalarSubquery(\Nisalatp\DynamicReportGenerator\Types\VirtualAttributeRequest $request): string
    {
        $this->ensureModelsLoaded();
        $this->ensureModelAllowed($request->baseModel);
        $this->ensureModelAllowed($request->targetModel);

        $links = $this->discoverLinks();
        
        // Find path from Target Model to Base Model using 'va_sub' as alias prefix
        $plan = $this->planJoins($request->targetModel, [$request->baseModel], $links, 'va_sub');

        $targetInstance = new $request->targetModel;
        
        $query = \Illuminate\Support\Facades\DB::table($targetInstance->getTable() . ' as va_sub0');
        
        $aliases = [$request->targetModel => 'va_sub0'];
        foreach ($plan->steps as $step) {
            $toInstance = new $step->toModel();
            
            $aliases[$step->fromModel] = $step->localTableAlias;
            $aliases[$step->toModel] = $step->remoteTableAlias;
            
            $first = $step->localTableAlias . '.' . $step->localKey;
            $second = $step->remoteTableAlias . '.' . $step->foreignKey;
            
            if ($step->relationType === 'BelongsTo') {
                $first = $step->localTableAlias . '.' . $step->foreignKey;
                $second = $step->remoteTableAlias . '.' . $step->localKey;
            }

            $query->join($toInstance->getTable() . ' as ' . $step->remoteTableAlias, $first, '=', $second, 'inner');
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
     * Get the final generated columns (aliases) from the report request.
     * This is useful for populating frontend dropdowns for HAVING and ORDER BY.
     */
    public function getGeneratedColumns(ReportRequest $whatUserWants): array
    {
        $columns = [];
        
        $getInnerAlias = function(\Nisalatp\DynamicReportGenerator\Types\Attribute $attr) use ($whatUserWants) {
            foreach ($whatUserWants->selectedAttributes as $selAttr) {
                if ($selAttr->modelClass === $attr->modelClass && $selAttr->column === $attr->column) {
                    return $selAttr->alias ?? $selAttr->column;
                }
            }
            return $attr->column;
        };

        if (!empty($whatUserWants->groupBys) || !empty($whatUserWants->aggregates)) {
            foreach ($whatUserWants->groupBys as $g) {
                $innerColName = $getInnerAlias($g->attribute);
                if (!in_array($innerColName, $columns)) {
                    $columns[] = $innerColName;
                }
            }
            foreach ($whatUserWants->aggregates as $a) {
                $func = strtoupper($a->function);
                $innerColName = $getInnerAlias($a->attribute);
                $colName = $a->alias ?? strtolower($func . '_' . preg_replace('/\s+/', '_', $innerColName));
                if (!in_array($colName, $columns)) {
                    $columns[] = $colName;
                }
            }
        } else {
            foreach ($whatUserWants->selectedAttributes as $attr) {
                $colName = $attr->alias ?? $attr->column;
                if (str_starts_with($colName, 'va:')) {
                    $colName = substr($colName, 3);
                }
                if (!in_array($colName, $columns)) {
                    $columns[] = $colName;
                }
            }
        }
        
        return $columns;
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
                    $validDeps = array_filter($va->dependencies, fn($d) => is_string($d) && $d !== '_ast');
                    $targetModels = array_merge($targetModels, $validDeps);
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
                    $validDeps = array_filter($va->dependencies, fn($d) => is_string($d) && $d !== '_ast');
                    $targetModels = array_merge($targetModels, $validDeps);
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
     * Schema Discovery: Get all available reportable models (All models MINUS restricted models).
     *
     * @return array Array of allowed model class names.
     */
    public function getAvailableModels(): array
    {
        $this->ensureModelsLoaded();
        return array_keys($this->allowedModels);
    }

    /**
     * Schema Discovery: Discover and return ALL Eloquent models in the application.
     *
     * @return array Array of all model class names.
     */
    public function getAllApplicationModels(): array
    {
        if ($this->allApplicationModels !== null) {
            return $this->allApplicationModels;
        }

        $models = [];
        $modelPaths = [app_path(), app_path('Models')];
        
        $finder = new Finder();
        $finder->files()->name('*.php')->in(array_filter($modelPaths, 'is_dir'));

        foreach ($finder as $file) {
            $path = $file->getRealPath();
            // A simple heuristic to find namespace and class name without regexing everything,
            // or simply use token_get_all. We'll use a reliable token extraction:
            $class = $this->extractClassFromFile($path);
            if ($class && class_exists($class) && is_subclass_of($class, Model::class)) {
                // Ensure it's not abstract or interface
                $reflection = new ReflectionClass($class);
                if (!$reflection->isAbstract() && !$reflection->isInterface()) {
                    $models[] = $class;
                }
            }
        }

        $this->allApplicationModels = array_unique($models);
        return $this->allApplicationModels;
    }

    /**
     * Schema Discovery: Get the list of explicitly restricted model classes.
     *
     * @return array Array of restricted model class names.
     */
    public function getRestrictedModels(): array
    {
        if ($this->restrictedModels !== null) {
            return $this->restrictedModels;
        }
        
        try {
            $this->restrictedModels = RestrictedModel::pluck('model_class')->toArray();
        } catch (\Exception $e) {
            // Fallback if table doesn't exist yet (e.g., during testing or before migration)
            $this->restrictedModels = [];
        }

        return $this->restrictedModels;
    }

    /**
     * Schema Discovery: Explicitly restrict a model from being used in the report generator.
     *
     * @param string $modelClass The fully qualified class name of the model to restrict.
     * @param int|null $actionByUserId Optional user ID for auditing.
     * @return void
     */
    public function restrictModel(string $modelClass, ?int $actionByUserId = null): void
    {
        RestrictedModel::firstOrCreate([
            'model_class' => $modelClass,
        ], [
            'restricted_by' => $actionByUserId,
        ]);

        // Invalidate caches
        $this->restrictedModels = null;
        $this->allowedModels = null;
        $this->cachedLinks = null;
    }

    /**
     * Schema Discovery: Remove a restriction from a model.
     *
     * @param string $modelClass The fully qualified class name of the model to unrestrict.
     * @return void
     */
    public function unrestrictModel(string $modelClass): void
    {
        RestrictedModel::where('model_class', $modelClass)->delete();

        // Invalidate caches
        $this->restrictedModels = null;
        $this->allowedModels = null;
        $this->cachedLinks = null;
    }

    private function extractClassFromFile(string $path): ?string
    {
        $contents = file_get_contents($path);
        $namespace = '';
        $class = '';

        $tokens = token_get_all($contents);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j] === ';') break;
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED])) {
                        $namespace .= $tokens[$j][1];
                    }
                }
            }

            if ($tokens[$i][0] === T_CLASS) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j] === '{') break;
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $class = $tokens[$j][1];
                        break;
                    }
                }
                break;
            }
        }

        if ($class) {
            return $namespace ? $namespace . '\\' . $class : $class;
        }

        return null;
    }

    /**
     * Resolve all attribute restrictions for the given execution subjects.
     */
    private function resolveAttributeRestrictions(?array $subjects): void
    {
        $this->resolvedRestrictions = [];
        
        if ($subjects === null && function_exists('auth') && auth()->check()) {
            $user = auth()->user();
            if ($user instanceof DynamicReportSubject) {
                $subjects = $user->getDynamicReportSubjects();
            } else {
                $subjects = [$user];
            }
        }
        
        if (empty($subjects)) {
            return;
        }

        $query = AttributeRestriction::query();
        $query->where(function($q) use ($subjects) {
            foreach ($subjects as $subject) {
                if ($subject instanceof Model) {
                    $q->orWhere(function($sq) use ($subject) {
                        $sq->where('subject_type', get_class($subject))
                           ->where('subject_id', $subject->getKey());
                    });
                }
            }
        });

        try {
            $restrictions = $query->get();
            foreach ($restrictions as $r) {
                $key = $r->model_class . '.' . $r->attribute;
                // Blocked takes precedence over masked
                if (!isset($this->resolvedRestrictions[$key]) || $this->resolvedRestrictions[$key] !== 'blocked') {
                    $this->resolvedRestrictions[$key] = $r->restriction_type;
                }
            }
        } catch (\Exception $e) {
            // Ignore if table doesn't exist yet
        }
    }

    private function getRestrictionType(string $modelClass, string $attribute, bool $isVirtual = false): ?string
    {
        if ($isVirtual && !str_starts_with($attribute, 'va:')) {
            $attribute = 'va:' . $attribute;
        }
        return $this->resolvedRestrictions[$modelClass . '.' . $attribute] ?? null;
    }

    private function extractAttributesFromFilter(FilterNode $node): array
    {
        if ($node instanceof FilterLeaf) {
            return [$node->attribute];
        }
        if ($node instanceof FilterGroup) {
            $attrs = [];
            foreach ($node->children as $child) {
                $attrs = array_merge($attrs, $this->extractAttributesFromFilter($child));
            }
            return $attrs;
        }
        return [];
    }

    private function validateSecurity(ReportRequest $req): void
    {
        $checkBlocked = function(array $attributes, string $context) {
            foreach ($attributes as $attr) {
                if ($this->getRestrictionType($attr->modelClass, $attr->column, $attr->isVirtual) === 'blocked') {
                    throw new ReportMakerSecurityException("Attribute {$attr->modelClass}.{$attr->column} is BLOCKED and cannot be used in {$context} calculations.");
                }
            }
        };

        if ($req->innerFilters) {
            $checkBlocked($this->extractAttributesFromFilter($req->innerFilters), 'filters');
        }
        if ($req->outerFilters) {
            $checkBlocked($this->extractAttributesFromFilter($req->outerFilters), 'filters');
        }
        if ($req->groupBys) {
            $gbAttrs = array_map(fn($g) => $g->attribute, $req->groupBys);
            $checkBlocked($gbAttrs, 'group bys');
        }
        if ($req->aggregates) {
            $aggAttrs = array_map(fn($a) => $a->attribute, $req->aggregates);
            $checkBlocked($aggAttrs, 'aggregates');
        }
        if ($req->sorts) {
            $sortAttrs = array_map(fn($s) => $s->attribute, $req->sorts);
            $checkBlocked($sortAttrs, 'sorts');
        }
    }

    /**
     * Schema Discovery: Get all attributes (physical and virtual) for a model, excluding blocked ones.
     *
     * @param string $modelClass
     * @return array Array of attribute names.
     */
    public function getModelAttributes(string $modelClass): array
    {
        $this->ensureModelsLoaded();
        $this->ensureModelAllowed($modelClass);
        
        $table = $this->allowedModels[$modelClass]->table;
        $physicalCols = Schema::getColumnListing($table);

        $virtualCols = [];
        if ($this->vaRegistry) {
            $virtualAttrs = $this->vaRegistry->getForModel($modelClass);
            $virtualCols = $virtualAttrs->map(function($va) { return 'va:' . $va->name; })->toArray();
        }

        $allCols = array_merge($physicalCols, $virtualCols);
        
        // Exclude blocked attributes from schema discovery so they don't even show up in UIs
        // We load restrictions for the current active user here.
        $this->resolveAttributeRestrictions(null); 
        
        return array_values(array_filter($allCols, function($col) use ($modelClass) {
            return $this->getRestrictionType($modelClass, $col, str_starts_with($col, 'va:')) !== 'blocked';
        }));
    }

    /**
     * Schema Discovery: Get all discoverable relationships for a model.
     *
     * @param string $modelClass
     * @return array Array of ModelLink objects detailing the relationships.
     */
    public function getModelRelationships(string $modelClass): array
    {
        $this->ensureModelsLoaded();
        $this->ensureModelAllowed($modelClass);
        
        $links = $this->discoverLinks();
        return $links[$modelClass] ?? [];
    }

    /**
     * Get all models connected to the given model via both forward and reverse relations.
     *
     * This is the bidirectional view of the relationship graph for a specific model,
     * merging explicitly declared Eloquent relationships with synthesized reverse edges.
     * Useful for UI components that need to show all reachable models from a starting point.
     *
     * @param string $modelClass The fully qualified model class name.
     * @return array<string, ModelLink> Map of connected model class => ModelLink.
     */
    public function getConnectedModels(string $modelClass): array
    {
        $this->ensureModelsLoaded();
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

    /**
     * Discover all relationship links between allowed models (bidirectional).
     *
     * This is the core of the BFS graph construction. It first discovers forward
     * relationships (those explicitly declared on models via Eloquent methods),
     * then synthesizes reverse edges for any relationship that only has one
     * direction defined. The result is a fully bidirectional adjacency list.
     *
     * The graph is cached in-memory for the lifetime of this ReportMaker instance
     * to avoid repeated reflection overhead on subsequent calls.
     *
     * @return array<string, array<string, ModelLink>> Adjacency list: model => [model => link]
     */
    private function discoverLinks(): array
    {
        // Return cached graph if already computed (avoids repeated reflection)
        if ($this->cachedLinks !== null) {
            return $this->cachedLinks;
        }

        // Phase 1: Discover forward-declared relationships via reflection.
        // These are the edges that developers explicitly defined on their models
        // (e.g., Order::user() returns BelongsTo, User::orders() returns HasMany).
        $forwardLinks = $this->getForwardRelations();

        // Phase 2: Synthesize reverse edges for any one-directional relationships.
        // If Model A declares a relationship to Model B but Model B does not declare
        // the inverse, we infer the reverse edge so BFS can traverse in both directions.
        // This is critical — without it, BFS can only follow the direction in which
        // relationships were declared, missing valid join paths in the opposite direction.
        $allLinks = $this->getReverseRelations($forwardLinks);

        $this->cachedLinks = $allLinks;
        return $allLinks;
    }

    /**
     * Forward relation discovery: scan each allowed model's public methods via
     * reflection to find declared Eloquent relationships.
     *
     * Supported types: BelongsTo, HasOne, HasMany, BelongsToMany.
     *
     * Each discovered relationship becomes a directed edge in the graph:
     *   ModelA --[relationType]--> ModelB
     *
     * For example, if Order has a `user()` method returning BelongsTo(User),
     * we record: Order --[BelongsTo]--> User, with foreignKey='user_id', localKey='id'.
     *
     * @return array<string, array<string, ModelLink>>
     */
    private function getForwardRelations(): array
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
                            fromModel: $modelClass,
                            toModel: $toModel,
                            type: $type,
                            foreignKey: $foreignKey,
                            localKey: $localKey,
                            methodName: $method->getName(),
                            direction: 'forward',
                        );
                    }
                } catch (\Throwable $e) {}
            }
        }
        return $links;
    }

    /**
     * Reverse relation synthesis: for every forward edge A→B, check if the
     * inverse edge B→A already exists. If not, synthesize one by inverting
     * the relationship type and reusing the same foreign/local keys.
     *
     * Why this is necessary:
     * Eloquent models often only declare one direction of a relationship.
     * For example, OrderItem might have `product()` returning BelongsTo(Product),
     * but Product might not define `orderItems()` returning HasMany(OrderItem).
     * Without reverse edges, the BFS pathfinder cannot traverse from Product
     * to OrderItem, making it impossible to discover join paths that start
     * from Product.
     *
     * In a family-relationship context, if a Person model stores `father_id`
     * and `mother_id` as BelongsTo relationships, the forward edges go from
     * child to parent. Without reverse synthesis, BFS cannot traverse from
     * parent to child — blocking discovery of grandchildren, siblings,
     * uncles, cousins, and all "downward" relationship paths.
     *
     * Relationship type inversion rules:
     *   BelongsTo    → HasMany   (child→parent becomes parent→children)
     *   HasMany      → BelongsTo (parent→children becomes child→parent)
     *   HasOne       → BelongsTo (parent→child becomes child→parent)
     *   BelongsToMany→ BelongsToMany (swap pivot keys)
     *
     * Key handling:
     *   For BelongsTo/HasOne/HasMany inversions, the foreign and local keys
     *   stay identical — the join builder already handles BelongsTo key
     *   swapping in buildInnerQuery(). For BelongsToMany, the pivot keys
     *   are swapped since the "owning" side flips.
     *
     * @param array<string, array<string, ModelLink>> $forwardLinks The forward-only graph.
     * @return array<string, array<string, ModelLink>> The merged bidirectional graph.
     */
    private function getReverseRelations(array $forwardLinks): array
    {
        $allLinks = $forwardLinks;

        foreach ($forwardLinks as $fromModel => $targets) {
            foreach ($targets as $toModel => $link) {
                // If the developer already declared both directions, respect that.
                // Don't overwrite an explicit definition with a synthesized one.
                if (isset($allLinks[$toModel][$fromModel])) {
                    continue;
                }

                // Invert the relationship type to get the correct reverse edge.
                // BelongsTo (child→parent) becomes HasMany (parent→children).
                // HasMany/HasOne (parent→child) becomes BelongsTo (child→parent).
                // BelongsToMany is symmetric but needs swapped pivot keys.
                $reverseType = match ($link->type) {
                    'BelongsTo' => 'HasMany',
                    'HasOne', 'HasMany' => 'BelongsTo',
                    'BelongsToMany' => 'BelongsToMany',
                    default => null,
                };

                if ($reverseType === null) {
                    continue;
                }

                // For BelongsToMany, swap the pivot keys since the "owning" side flips.
                // For all other types, the foreign/local keys stay the same because
                // buildInnerQuery() already handles the BelongsTo key swap at JOIN time.
                $reverseForeignKey = $link->type === 'BelongsToMany' ? $link->localKey : $link->foreignKey;
                $reverseLocalKey = $link->type === 'BelongsToMany' ? $link->foreignKey : $link->localKey;

                if (!isset($allLinks[$toModel])) {
                    $allLinks[$toModel] = [];
                }

                $allLinks[$toModel][$fromModel] = new ModelLink(
                    fromModel: $toModel,
                    toModel: $fromModel,
                    type: $reverseType,
                    foreignKey: $reverseForeignKey,
                    localKey: $reverseLocalKey,
                    methodName: $link->methodName . '_reverse',
                    direction: 'reverse',
                );
            }
        }

        return $allLinks;
    }

    /**
     * Build the join plan by resolving BFS shortest paths from the base model
     * to each target model, then converting each path edge into a JoinStep.
     *
     * Thanks to bidirectional link discovery, this can now resolve paths that
     * traverse relationships in either direction — even when only one side
     * of the relationship was explicitly declared on the model.
     */
    private function planJoins(string $base, array $targets, array $links, string $aliasPrefix = 't'): JoinPlan
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

                // Propagate the link's direction (forward/reverse) into the JoinStep
                // so the join plan carries full traversal metadata for debugging
                // and for the frontend's join plan visualizer.
                $steps[] = new JoinStep(
                    fromModel: $from,
                    toModel: $to,
                    joinType: 'left',
                    localTableAlias: $i === 0 ? $aliasPrefix . '0' : $aliasPrefix . ($aliasCounter - 1),
                    remoteTableAlias: $aliasPrefix . $aliasCounter,
                    localKey: $link->localKey,
                    foreignKey: $link->foreignKey,
                    relationType: $link->type,
                    direction: $link->direction,
                );
                $aliasCounter++;
            }
        }
        return new JoinPlan($steps);
    }

    /**
     * BFS shortest-path finder between two models in the relationship graph.
     *
     * Traverses the bidirectional adjacency list built by discoverLinks() to
     * find the shortest sequence of model hops from $start to $end. Each model
     * class can appear at most once in the path (standard BFS visited guard),
     * which prevents cycles through spouse loops, self-referencing models, or
     * any other circular relationship patterns.
     *
     * The visited set tracks model class names — since each class is unique in
     * the graph, this is sufficient to prevent infinite loops without needing
     * path-signature-based tracking. Path signatures would only be needed for
     * person-instance-level traversal (where the same Person model class
     * connects to itself via parent/child/spouse edges).
     *
     * @param string $start Starting model class.
     * @param string $end   Target model class.
     * @param array  $links Bidirectional adjacency list from discoverLinks().
     * @return array|null Array of model class names forming the shortest path, or null.
     */
    private function findShortestPath(string $start, string $end, array $links): ?array
    {
        $queue = [[$start]];
        $visited = [$start => true];

        while (!empty($queue)) {
            $path = array_shift($queue);
            $current = end($path);

            if ($current === $end) return $path;

            // Iterate all neighbors — this now includes both forward-declared
            // and reverse-synthesized edges, enabling bidirectional traversal.
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

    private function buildInnerQuery(string $base, JoinPlan $plan, array $selects, ?FilterNode $filters, ?array $subjects = null): Builder
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
            
            $restriction = $this->getRestrictionType($attr->modelClass, $attr->column, $isVirtual);
            if ($restriction === 'blocked') {
                $selectColumns[] = DB::raw("'###' as " . $query->getGrammar()->wrap($finalAlias));
                continue;
            } elseif ($restriction === 'masked') {
                $selectColumns[] = DB::raw("'***' as " . $query->getGrammar()->wrap($finalAlias));
                continue;
            }

            if ($isVirtual && $this->vaRegistry) {
                $name = str_starts_with($attr->column, 'va:') ? substr($attr->column, 3) : $attr->column;
                $va = $this->vaRegistry->findByName($base, $name);
                if ($va) {
                    $selectColumns[] = DB::raw($va->sql_fragment . ' as "' . $finalAlias . '"');
                    continue;
                } else {
                    throw new \Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerException("Virtual Attribute '{$name}' is missing or deleted. This query cannot be executed.");
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

    /**
     * ALS Management: Restrict an attribute for a specific subject.
     */
    public function restrictAttribute(string $modelClass, string $attribute, \Illuminate\Database\Eloquent\Model $subject, string $type = 'masked'): void
    {
        AttributeRestriction::updateOrCreate([
            'model_class' => $modelClass,
            'attribute' => $attribute,
            'subject_type' => get_class($subject),
            'subject_id' => $subject->getKey(),
        ], [
            'restriction_type' => $type,
            'created_by' => function_exists('auth') && auth()->check() ? auth()->id() : null,
        ]);
        $this->resolvedRestrictions = []; // clear cache
    }

    /**
     * ALS Management: Remove a restriction.
     */
    public function unrestrictAttribute(string $modelClass, string $attribute, \Illuminate\Database\Eloquent\Model $subject): void
    {
        AttributeRestriction::where([
            'model_class' => $modelClass,
            'attribute' => $attribute,
            'subject_type' => get_class($subject),
            'subject_id' => $subject->getKey(),
        ])->delete();
        $this->resolvedRestrictions = [];
    }

    /**
     * ALS Management: Get all restrictions for a subject.
     */
    public function getAttributeRestrictions(\Illuminate\Database\Eloquent\Model $subject): array
    {
        return AttributeRestriction::where('subject_type', get_class($subject))
            ->where('subject_id', $subject->getKey())
            ->get()
            ->toArray();
    }
}
