<?php

namespace Nisalatp\DynamicReportGenerator\Facades;

use Illuminate\Support\Facades\Facade;
use Nisalatp\DynamicReportGenerator\ReportMaker;

/**
 * @method static \Illuminate\Database\Query\Builder generate(\Nisalatp\DynamicReportGenerator\Types\ReportRequest $request)
 * @method static \Illuminate\Contracts\Pagination\LengthAwarePaginator generatePaginated(\Nisalatp\DynamicReportGenerator\Types\ReportRequest $request, int $perPage = 50)
 * @method static \Symfony\Component\HttpFoundation\StreamedResponse exportToCsv(\Nisalatp\DynamicReportGenerator\Types\ReportRequest $request, string $filename = 'report.csv')
 * @method static string toRawSql(\Illuminate\Database\Query\Builder $query)
 * @method static \Nisalatp\DynamicReportGenerator\Types\JoinPlan explainJoinPlan(\Nisalatp\DynamicReportGenerator\Types\ReportRequest $request)
 * @method static array getAvailableModels()
 * @method static array getModelAttributes(string $modelClass)
 * @method static array getModelRelationships(string $modelClass)
 * @method static \Nisalatp\DynamicReportGenerator\Models\SavedReport saveReport(string $name, \Nisalatp\DynamicReportGenerator\Types\ReportRequest $request, ?int $userId = null, string $description = '')
 * @method static \Illuminate\Database\Eloquent\Collection getSavedReports()
 * @method static \Illuminate\Database\Query\Builder loadAndGenerate(int $savedReportId, ?int $executedByUserId = null)
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
