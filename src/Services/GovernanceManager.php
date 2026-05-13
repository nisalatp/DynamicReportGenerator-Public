<?php

namespace Nisalatp\DynamicReportGenerator\Services;

use Nisalatp\DynamicReportGenerator\Models\AttributeRestriction;
use Nisalatp\DynamicReportGenerator\Registry\VirtualAttributeRegistry;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class GovernanceManager
{
    /**
     * Get the security matrix for a given model and subject (User/Role).
     */
    public static function getMatrix(string $modelClass, string $subjectClass, int $subjectId): array
    {
        $instance = new $modelClass;
        $table = $instance->getTable();
        $physicalCols = Schema::getColumnListing($table);
        
        $virtualCols = [];
        $vaRegistry = app(VirtualAttributeRegistry::class);
        if ($vaRegistry) {
            $virtualAttrs = $vaRegistry->getForModel($modelClass);
            $virtualCols = $virtualAttrs->map(function($va) { return 'va:' . $va->name; })->toArray();
        }

        $attributes = array_merge($physicalCols, $virtualCols);
        
        // Get existing restrictions for this model for this subject
        $restrictions = AttributeRestriction::where('model_class', $modelClass)
            ->where('subject_type', $subjectClass)
            ->where('subject_id', $subjectId)
            ->get();

        $isReportable = true;
        $reportableRestriction = $restrictions->where('attribute', '*')->first();
        if ($reportableRestriction && $reportableRestriction->restriction_type === 'blocked') {
            $isReportable = false;
        }

        $matrix = [];
        foreach ($attributes as $attrName) {
            if ($attrName === '*') continue;

            $type = str_starts_with($attrName, 'va:') ? 'virtual' : 'physical';
            $restriction = $restrictions->where('attribute', $attrName)->first();
            
            $matrix[] = [
                'name' => $attrName,
                'type' => $type,
                'restriction' => $restriction ? $restriction->restriction_type : 'unrestricted'
            ];
        }

        return [
            'is_reportable' => $isReportable,
            'attributes' => $matrix
        ];
    }

    /**
     * Save the security matrix for a given model and subject.
     */
    public static function saveMatrix(string $modelClass, string $subjectClass, int $subjectId, bool $isReportable, array $attributes, int $authId): void
    {
        DB::beginTransaction();
        try {
            // Handle Model Reportability
            if (!$isReportable) {
                AttributeRestriction::updateOrCreate(
                    [
                        'model_class' => $modelClass,
                        'attribute' => '*',
                        'subject_type' => $subjectClass,
                        'subject_id' => $subjectId
                    ],
                    [
                        'restriction_type' => 'blocked',
                        'created_by' => $authId
                    ]
                );
            } else {
                AttributeRestriction::where([
                    'model_class' => $modelClass,
                    'attribute' => '*',
                    'subject_type' => $subjectClass,
                    'subject_id' => $subjectId
                ])->delete();
            }

            // Handle Attributes
            foreach ($attributes as $attrName => $restrictionType) {
                if ($restrictionType === 'unrestricted') {
                    AttributeRestriction::where([
                        'model_class' => $modelClass,
                        'attribute' => $attrName,
                        'subject_type' => $subjectClass,
                        'subject_id' => $subjectId
                    ])->delete();
                } else {
                    AttributeRestriction::updateOrCreate(
                        [
                            'model_class' => $modelClass,
                            'attribute' => $attrName,
                            'subject_type' => $subjectClass,
                            'subject_id' => $subjectId
                        ],
                        [
                            'restriction_type' => $restrictionType,
                            'created_by' => $authId
                        ]
                    );
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
