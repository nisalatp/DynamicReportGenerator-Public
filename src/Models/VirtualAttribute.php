<?php

namespace Nisalatp\DynamicReportGenerator\Models;

use Illuminate\Database\Eloquent\Model;

class VirtualAttribute extends Model
{
    protected $table = 'dynamic_virtual_attributes';

    protected $fillable = [
        'name',
        'base_model',
        'return_type',
        'sql_fragment',
        'dependencies',
    ];

    protected $casts = [
        'dependencies' => 'array',
    ];
}
