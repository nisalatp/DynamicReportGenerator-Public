<?php

namespace Nisalatp\DynamicReportGenerator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Attribute Restriction Model (Attribute-Level Security).
 *
 * This model serves as the core storage layer for the engine's Attribute-Level Security (ALS).
 * It dictates whether a specific subject (e.g., a User or a Role) is 'masked' or 'blocked'
 * from viewing or computing against a specific column in a model.
 *
 * @property int $id
 * @property string $model_class
 * @property string $attribute
 * @property string $restriction_type
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class AttributeRestriction extends Model
{
    protected $table = 'dynamic_attribute_restrictions';

    protected $fillable = [
        'model_class',
        'attribute',
        'restriction_type',
        'subject_type',
        'subject_id',
        'created_by',
    ];

    /**
     * Get the subject that this restriction applies to (User, Role, etc.).
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
