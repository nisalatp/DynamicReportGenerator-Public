<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Intentionally has NO relationships. Used to assert that the BFS join
 * resolver throws ReportMakerException::noPath() for unreachable models.
 */
class Category extends Model
{
    protected $table = 'categories';
    protected $guarded = [];
}
