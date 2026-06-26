<?php

namespace Nisalatp\DynamicReportGenerator\Types;

/**
 * Virtual Attribute Request.
 *
 * Represents the configuration DTO for an actively executing Virtual Attribute.
 * Used internally by the engine when compiling the embedded query fragments
 * for Virtual Attributes that have their own internal sub-filters.
 */
readonly class VirtualAttributeRequest
{
    public function __construct(
        public string $baseModel,
        public string $targetModel,
        public string $aggregateFunction,
        public string $aggregateColumn,
        public ?FilterNode $innerFilters = null,
        public ?FilterNode $outerFilters = null,
    ) {}

    public static function fromArray(array $data): self
    {
        $innerFilters = null;
        if (!empty($data['innerFilters'])) {
            $innerFilters = self::parseFilterNode($data['innerFilters']);
        }

        $outerFilters = null;
        if (!empty($data['outerFilters'])) {
            $outerFilters = self::parseFilterNode($data['outerFilters']);
        }

        return new self(
            baseModel: $data['baseModel'],
            targetModel: $data['targetModel'],
            aggregateFunction: $data['aggregateFunction'],
            aggregateColumn: $data['aggregateColumn'],
            innerFilters: $innerFilters,
            outerFilters: $outerFilters
        );
    }

    private static function parseFilterNode(array $node): ?FilterNode
    {
        if (($node['type'] ?? '') === 'group') {
            // Recursively parse children, filtering out any invalid nodes
            $children = collect($node['children'] ?? [])
                ->map(function ($child) {
                    return self::parseFilterNode($child);
                })
                ->filter()
                ->values()
                ->toArray();
                
            if (empty($children)) {
                return null;
            }
            
            return new FilterGroup($node['logic'] ?? 'and', $children);
        }

        if (($node['type'] ?? '') === 'leaf') {
            if (empty($node['column'])) return null;
            return new FilterLeaf(
                new Attribute($node['modelClass'] ?? $node['model'] ?? '', $node['column'], $node['dataType'] ?? 'string'),
                $node['operator'] ?? '=',
                $node['value'] ?? null
            );
        }

        return null;
    }
}
