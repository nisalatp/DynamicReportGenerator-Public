<?php

namespace Nisalatp\DynamicReportGenerator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportLog extends Model
{
    protected $table = 'dynamic_report_logs';

    protected $fillable = [
        'saved_report_id',
        'user_id',
        'action',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function savedReport(): BelongsTo
    {
        return $this->belongsTo(SavedReport::class, 'saved_report_id');
    }
}
