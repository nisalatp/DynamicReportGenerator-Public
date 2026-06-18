<?php

namespace Nisalatp\DynamicReportGenerator\Registry;

use Nisalatp\DynamicReportGenerator\Models\VirtualAttribute;

class VirtualAttributeRegistry
{
    /**
     * In-memory cache keyed by base_model class name.
     * Each entry holds all VirtualAttribute records for that model, indexed by name.
     * This ensures that across a single HTTP lifecycle the database is queried at most
     * once per model — regardless of how many columns or filters reference its VAs.
     *
     * @var array<string, array<string, VirtualAttribute>>
     */
    private array $cache = [];

    /**
     * Prime the in-memory cache for the given model if not already loaded.
     * All VAs for the model are fetched in a single query and stored by name.
     */
    private function ensureLoaded(string $modelClass): void
    {
        if (!array_key_exists($modelClass, $this->cache)) {
            $this->cache[$modelClass] = VirtualAttribute::where('base_model', $modelClass)
                ->get()
                ->keyBy('name')
                ->all();
        }
    }

    /**
     * Find a single Virtual Attribute by model and name.
     * Served from the in-memory cache after the first call per model.
     */
    public function findByName(string $modelClass, string $name): ?VirtualAttribute
    {
        $this->ensureLoaded($modelClass);
        return $this->cache[$modelClass][$name] ?? null;
    }

    /**
     * Retrieve all Virtual Attributes for a given model.
     * Served from the in-memory cache after the first call per model.
     *
     * @param string $modelClass
     * @return array<string, VirtualAttribute>
     */
    public function getForModel(string $modelClass): array
    {
        $this->ensureLoaded($modelClass);
        return array_values($this->cache[$modelClass]);
    }

    /**
     * Flush the cache for a specific model (or all models).
     * Call this after a VA is created, updated, or deleted so the next
     * request picks up the fresh state from the database.
     */
    public function flush(?string $modelClass = null): void
    {
        if ($modelClass !== null) {
            unset($this->cache[$modelClass]);
        } else {
            $this->cache = [];
        }
    }
}
