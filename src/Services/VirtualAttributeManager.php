<?php

namespace Nisalatp\DynamicReportGenerator\Services;

use Nisalatp\DynamicReportGenerator\Models\VirtualAttribute;
use Nisalatp\DynamicReportGenerator\Models\SavedReport;
use Illuminate\Database\Eloquent\Collection;

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
            throw new \Exception("This Virtual Attribute is used by {$usageCount} saved reports. Are you sure you want to force delete it? This will break those reports.");
        }

        $va->delete();
    }
}
