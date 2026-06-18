<?php

namespace Nisalatp\DynamicReportGenerator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Saved Report Model.
 *
 * Eloquent model responsible for persisting a generated report's AST payload
 * to the database. Acts as the anchor point for Report Ownership and assignment.
 */
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
        // Resolve the host application's configured User model so that custom
        // authenticatable models (e.g. App\Models\Admin) are supported without
        // any modification to this package. Falls back to Laravel's default User.
        $userModel = config('auth.providers.users.model', \Illuminate\Foundation\Auth\User::class);

        return $this->belongsToMany(
            $userModel,
            'dynamic_report_user',
            'saved_report_id',
            'user_id'
        )->withTimestamps();
    }
}
