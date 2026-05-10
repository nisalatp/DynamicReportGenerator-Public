<?php

namespace Nisalatp\DynamicReportGenerator\Registry;

use Nisalatp\DynamicReportGenerator\Models\VirtualAttribute;

class VirtualAttributeRegistry
{
    public function findByName(string $modelClass, string $name): ?VirtualAttribute
    {
        return VirtualAttribute::where('base_model', $modelClass)
            ->where('name', $name)
            ->first();
    }

    /**
     * Retrieve all Virtual Attributes for a given model.
     *
     * @param string $modelClass
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getForModel(string $modelClass)
    {
        return VirtualAttribute::where('base_model', $modelClass)->get();
    }
}
