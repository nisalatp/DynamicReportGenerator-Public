<?php

namespace Nisalatp\DynamicReportGenerator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Nisalatp\DynamicReportGenerator\Models\RestrictedModel
 *
 * @property int $id
 * @property string $model_class
 * @property int|null $restricted_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class RestrictedModel extends Model
{
    protected $table = 'dynamic_restricted_models';

    protected $fillable = [
        'model_class',
        'restricted_by',
    ];
}
