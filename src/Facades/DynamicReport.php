<?php

namespace Nisalatp\DynamicReportGenerator\Facades;

use Illuminate\Support\Facades\Facade;
use Nisalatp\DynamicReportGenerator\ReportMaker;

/**
 * --- Core Engine & Query Generation ---
 * @method static \Illuminate\Database\Query\Builder generate(\Nisalatp\DynamicReportGenerator\Types\ReportRequest $request, ?array $subjects = null)
 * @method static \Illuminate\Contracts\Pagination\LengthAwarePaginator generatePaginated(\Nisalatp\DynamicReportGenerator\Types\ReportRequest $request, int $perPage = 50, ?array $subjects = null)
 * @method static \Symfony\Component\HttpFoundation\StreamedResponse exportToCsv(\Nisalatp\DynamicReportGenerator\Types\ReportRequest $request, string $filename = 'report.csv', ?array $subjects = null)
 * @method static string toRawSql(\Illuminate\Database\Query\Builder $query)
 * @method static \Nisalatp\DynamicReportGenerator\Types\JoinPlan explainJoinPlan(\Nisalatp\DynamicReportGenerator\Types\ReportRequest $request)
 * @method static array getGeneratedColumns(\Nisalatp\DynamicReportGenerator\Types\ReportRequest $request)
 * @method static string buildScalarSubquery(\Nisalatp\DynamicReportGenerator\Types\VirtualAttributeRequest $request)
 *
 * --- Schema & Metadata Discovery ---
 * @method static array getAvailableModels()
 * @method static array getAllApplicationModels()
 * @method static array getModelAttributes(string $modelClass)
 * @method static array getModelRelationships(string $modelClass)
 * @method static array getConnectedModels(string $modelClass)
 * @method static int getMaxFilterDepth()
 *
 * --- Report Persistence & State Management ---
 * @method static \Nisalatp\DynamicReportGenerator\Models\SavedReport saveReport(string $name, \Nisalatp\DynamicReportGenerator\Types\ReportRequest $request, ?int $userId = null, string $description = '')
 * @method static \Illuminate\Database\Eloquent\Collection getSavedReports()
 * @method static \Illuminate\Database\Query\Builder loadAndGenerate(int $savedReportId, ?int $executedByUserId = null)
 * @method static \Nisalatp\DynamicReportGenerator\Types\ReportRequest loadToEditor(int $reportId)
 * @method static \Nisalatp\DynamicReportGenerator\Models\SavedReport updateReport(int $reportId, string $name, \Nisalatp\DynamicReportGenerator\Types\ReportRequest $request, string $description = '', ?int $actionByUserId = null)
 * @method static void deleteReport(int $reportId, ?int $actionByUserId = null)
 *
 * --- Report Access Control & Auditing ---
 * @method static void assignReport(int $reportId, int $userId, ?int $actionByUserId = null)
 * @method static void unassignReport(int $reportId, int $userId, ?int $actionByUserId = null)
 * @method static \Illuminate\Database\Eloquent\Collection getAssignedReports(int $userId)
 * @method static \Illuminate\Database\Eloquent\Collection getReportLogs(int $savedReportId)
 *
 * --- Model-Level Security ---
 * @method static void restrictModel(string $modelClass, ?int $actionByUserId = null)
 * @method static void unrestrictModel(string $modelClass)
 * @method static array getRestrictedModels()
 *
 * --- Attribute-Level Security ---
 * @method static void restrictAttribute(string $modelClass, string $attribute, \Illuminate\Database\Eloquent\Model $subject, string $type = 'masked')
 * @method static void unrestrictAttribute(string $modelClass, string $attribute, \Illuminate\Database\Eloquent\Model $subject)
 * @method static array getAttributeRestrictions(\Illuminate\Database\Eloquent\Model $subject)
 *
 * @see \Nisalatp\DynamicReportGenerator\ReportMaker
 */
class DynamicReport extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ReportMaker::class;
    }
}
