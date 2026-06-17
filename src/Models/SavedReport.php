<?php

namespace Nisalatp\DynamicReportGenerator\Models;

use Illuminate\Database\Eloquent\Model;

class SavedReport extends Model
{
    protected $table = 'dynamic_saved_reports';

    protected $fillable = [
        'name',
        'description',
        'payload',
        'user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'payload' => 'array',
    ];

    public function logs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ReportLog::class, 'saved_report_id');
    }

    public function assignedUsers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            \Illuminate\Foundation\Auth\User::class, // Defaults to the standard User model. Client can override if needed.
            'dynamic_report_user',
            'saved_report_id',
            'user_id'
        )->withTimestamps();
    }
}
