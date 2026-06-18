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
            $virtualCols = array_map(fn($va) => 'va:' . $va->name, $virtualAttrs);
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
        foreach ($attributes as $attributeName) {
            if ($attributeName === '*') continue;

            $type = str_starts_with($attributeName, 'va:') ? 'virtual' : 'physical';
            $restriction = $restrictions->where('attribute', $attributeName)->first();
            
            $matrix[] = [
                'name' => $attributeName,
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
            // 1. Handle Model-Level Reportability
            // If a model is not reportable, we insert a wildcard '*' restriction.
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
                // Remove the wildcard restriction if it exists
                AttributeRestriction::where([
                    'model_class' => $modelClass,
                    'attribute' => '*',
                    'subject_type' => $subjectClass,
                    'subject_id' => $subjectId
                ])->delete();
            }

            // 2. Process Individual Attribute Rules
            foreach ($attributes as $attributeName => $restrictionType) {
                
                // If unrestricted, ensure no restriction record exists in the database
                if ($restrictionType === 'unrestricted') {
                    AttributeRestriction::where([
                        'model_class' => $modelClass,
                        'attribute' => $attributeName,
                        'subject_type' => $subjectClass,
                        'subject_id' => $subjectId
                    ])->delete();
                } else {
                    // Otherwise, upsert the restriction (masked or blocked)
                    AttributeRestriction::updateOrCreate(
                        [
                            'model_class' => $modelClass,
                            'attribute' => $attributeName,
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
