<?php

namespace Nisalatp\DynamicReportGenerator\Registry;

use Illuminate\Database\Eloquent\Collection;
use Nisalatp\DynamicReportGenerator\Models\VirtualAttribute;

class VirtualAttributeRegistry
{
    /**
     * In-memory cache of Virtual Attributes keyed by base model class.
     * Populated with a single query on first access to a model and reused for
     * the remainder of the request lifecycle, eliminating the N+1 query problem
     * when a report references several Virtual Attributes on the same model.
     *
     * @var array<string, \Illuminate\Database\Eloquent\Collection>
     */
    private array $cache = [];

    /**
     * Fetch (and memoise) all Virtual Attributes registered for a model in a
     * single query. Subsequent calls for the same model are served from memory.
     */
    private function load(string $modelClass): Collection
    {
        if (!array_key_exists($modelClass, $this->cache)) {
            $this->cache[$modelClass] = VirtualAttribute::where('base_model', $modelClass)->get();
        }

        return $this->cache[$modelClass];
    }

    public function findByName(string $modelClass, string $name): ?VirtualAttribute
    {
        return $this->load($modelClass)->firstWhere('name', $name);
    }

    /**
     * Retrieve all Virtual Attributes for a given model (served from cache).
     *
     * @param string $modelClass
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getForModel(string $modelClass)
    {
        return $this->load($modelClass);
    }
}
