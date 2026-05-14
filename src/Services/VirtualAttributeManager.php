<?php

namespace Nisalatp\DynamicReportGenerator\Services;

use Nisalatp\DynamicReportGenerator\Models\VirtualAttribute;
use Nisalatp\DynamicReportGenerator\Models\SavedReport;
use Illuminate\Database\Eloquent\Collection;

/**
 * Virtual Attribute Manager.
 *
 * Handles the lifecycle and dependency tracking of Virtual Attributes. 
 * Provides safeguards against deleting attributes that are actively embedded 
 * inside saved report ASTs to prevent breaking user dashboards.
 */
class VirtualAttributeManager
{
    /**
     * Get all Virtual Attributes with their active usage counts mapped.
     */
    public static function getAllWithUsageCounts(): Collection
    {
        return VirtualAttribute::all()->map(function ($va) {
            $va->usage_count = self::getUsageCount($va);
            return $va;
        });
    }

    /**
     * Calculate how many Saved Reports are currently utilizing this Virtual Attribute anywhere in their AST payload.
     */
    public static function getUsageCount(VirtualAttribute $va): int
    {
        return SavedReport::where('payload', 'like', '%"va:' . $va->name . '"%')->count();
    }

    /**
     * Safely delete a Virtual Attribute, preventing deletion if it is in use unless $force is true.
     * Throws an exception if deletion is prevented.
     */
    public static function safeDelete(int $id, bool $force = false): void
    {
        $va = VirtualAttribute::findOrFail($id);
        
        $usageCount = self::getUsageCount($va);
        
        if ($usageCount > 0 && !$force) {
            throw new \Exception(
                "Deletion prevented: This Virtual Attribute is actively used by {$usageCount} saved report(s). " .
                "Are you sure you want to force delete it? Doing so will break those reports."
            );
        }

        $va->delete();
    }
}
