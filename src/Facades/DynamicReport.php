<?php

namespace Nisalatp\DynamicReportGenerator\Facades;

use Illuminate\Support\Facades\Facade;
use Nisalatp\DynamicReportGenerator\ReportMaker;

/**
 * @method static \Illuminate\Database\Query\Builder generate(\Nisalatp\DynamicReportGenerator\Types\ReportRequest $request)
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
