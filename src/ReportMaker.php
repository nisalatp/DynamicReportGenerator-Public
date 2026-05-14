<?php

namespace Nisalatp\DynamicReportGenerator;

use Illuminate\Database\Query\Builder;
use Nisalatp\DynamicReportGenerator\Types\ReportRequest;
use Nisalatp\DynamicReportGenerator\Types\Attribute;
use Nisalatp\DynamicReportGenerator\Registry\VirtualAttributeRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dynamic Report Generator — Core Engine Orchestrator.
 *
 * This is the central class that powers the entire reporting engine. It delegates
 * to six single-responsibility Traits, each handling a distinct architectural concern:
 *
 *   - DiscoversSchema:           Model discovery, attribute listing, class extraction
 *   - DiscoversRelationships:    Bidirectional graph construction and BFS pathfinding
 *   - CompilesQueries:           SQL compilation (SELECT, JOIN, WHERE, GROUP BY, HAVING)
 *   - EnforcesSecurity:          ALS resolution, validation, model/attribute restrictions
 *   - ManagesReports:            SavedReport CRUD, assignment, audit logging
 *   - ResolvesVirtualAttributes: VA dependency extraction from the AST
 *
 * The public API surface is exposed via the DynamicReport Facade and remains
 * unchanged — all 37 public methods resolve through this class.
 */
class ReportMaker
{
    use Concerns\DiscoversSchema;
    use Concerns\DiscoversRelationships;
    use Concerns\CompilesQueries;
    use Concerns\EnforcesSecurity;
    use Concerns\ManagesReports;
    use Concerns\ResolvesVirtualAttributes;

    /**
     * Internal package models that are excluded from reportable tables by default.
     * These are infrastructure tables used by the engine itself and should not
     * appear in model-selection UIs unless explicitly enabled via config.
     */
    private const INTERNAL_MODELS = [
        Models\SavedReport::class,
        Models\ReportLog::class,
        Models\RestrictedModel::class,
        Models\AttributeRestriction::class,
        Models\VirtualAttribute::class,
    ];

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

    /**
     * Generate a report query from a strict AST request.
     *
     * This is the main pipeline orchestrator that coordinates:
     * 1. Model validation (DiscoversSchema)
     * 2. Security enforcement (EnforcesSecurity)
     * 3. VA dependency extraction (ResolvesVirtualAttributes)
     * 4. BFS join planning (DiscoversRelationships)
     * 5. SQL compilation (CompilesQueries)
     *
     * @param ReportRequest $whatUserWants The strict AST payload.
     * @param array|null $subjects Optional DynamicReportSubject array for ALS resolution.
     * @return Builder The compiled query, ready for ->get(), ->paginate(), or ->cursor().
     */
    public function generate(ReportRequest $request, ?array $subjects = null): Builder
    {
        // 1. Schema Validation & Security Enforcement
        $this->ensureModelsLoaded();
        $this->ensureModelAllowed($request->baseModel);
        
        $this->resolveAttributeRestrictions($subjects);
        $this->validateSecurity($request);
        
        $this->validateFilterDepth($request->innerFilters, 'WHERE');
        $this->validateFilterDepth($request->outerFilters, 'HAVING');

        // 2. Virtual Attribute Dependency Extraction
        $targetModels = $request->targetModels;
        $this->extractVirtualAttributeDependencies($request, $targetModels);

        foreach ($targetModels as $model) {
            $this->ensureModelAllowed($model);
        }

        // 3. Bidirectional Graph Construction & Join Planning
        $links = $this->discoverLinks();
        $joinPlan = $this->planJoins($request->baseModel, $targetModels, $links);

        // 4. SQL Compilation Pipeline
        $innerQuery = $this->buildInnerQuery(
            $request->baseModel,
            $joinPlan,
            $request->selectedAttributes,
            $request->innerFilters
        );

        $hasComplexOuterDependencies = !empty($request->groupBys) 
            || !empty($request->aggregates) 
            || $request->outerFilters !== null;

        if ($hasComplexOuterDependencies) {
            $finalQuery = $this->buildOuterQuery(
                $request->baseModel,
                $innerQuery,
                $request->groupBys,
                $request->aggregates,
                $request->outerFilters,
                $request->selectedAttributes
            );
        } else {
            $finalQuery = $innerQuery;
        }

        // 5. Apply Ordering
        if (!empty($request->sorts)) {
            foreach ($request->sorts as $sort) {
                $colName = $sort->attribute->alias ?? $sort->attribute->column;
                
                // Strip virtual prefix for SQL sorting
                if (str_starts_with($colName, 'va:')) {
                    $colName = substr($colName, 3);
                }
                
                $finalQuery->orderBy($colName, $sort->direction);
            }
        }

        // 6. Enforce safety limits
        // Apply the configured max_rows safety limit to protect the host database from runaway queries.
        $maxRows = config('dynamicreportgenerator.limits.max_rows');
        if ($maxRows) {
            $finalQuery->limit((int) $maxRows);
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
                $array = (array) $record;
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
    public function explainJoinPlan(ReportRequest $whatUserWants): Types\JoinPlan
    {
        $this->ensureModelsLoaded();
        $this->ensureModelAllowed($whatUserWants->baseModel);

        $targetModels = $whatUserWants->targetModels;
        $this->extractVirtualAttributeDependencies($whatUserWants, $targetModels);

        foreach ($targetModels as $model) {
            $this->ensureModelAllowed($model);
        }

        $links = $this->discoverLinks();
        return $this->planJoins($whatUserWants->baseModel, $targetModels, $links);
    }

    /**
     * Get the final generated columns (aliases) from the report request.
     * This is useful for populating frontend dropdowns for HAVING and ORDER BY.
     */
    public function getGeneratedColumns(ReportRequest $whatUserWants): array
    {
        $columns = [];

        $getInnerAlias = function (Attribute $attr) use ($whatUserWants) {
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
}
