<?php

namespace Nisalatp\DynamicReportGenerator\Http\Requests;

use Nisalatp\DynamicReportGenerator\Types\ReportRequest;
use Nisalatp\DynamicReportGenerator\Types\Attribute;
use Nisalatp\DynamicReportGenerator\Types\FilterGroup;
use Nisalatp\DynamicReportGenerator\Types\FilterLeaf;
use Nisalatp\DynamicReportGenerator\Types\FilterNode;
use Nisalatp\DynamicReportGenerator\Types\GroupBy;
use Nisalatp\DynamicReportGenerator\Types\Aggregate;
use Nisalatp\DynamicReportGenerator\Types\Sort;
use InvalidArgumentException;

class ReportBuilderRequest
{
    /**
     * Parse a raw frontend JSON payload into a strict ReportRequest AST DTO.
     */
    public static function fromPayload(array $payload): ReportRequest
    {
        if (empty($payload['baseModel'])) {
            throw new InvalidArgumentException(
                'The "baseModel" key is required and must be a non-empty fully-qualified class name.'
            );
        }

        $baseModel = $payload['baseModel'];
        $targetModels = $payload['targetModels'] ?? [];

        $selectedAttributes = collect($payload['selectedAttributes'] ?? [])
            ->filter(fn($attr) => !empty($attr['column']))
            ->map(function ($attr) {
                return new Attribute($attr['modelClass'] ?? $attr['model'] ?? '', $attr['column'], $attr['type'] ?? 'string', false, null, $attr['alias'] ?? null);
            })->values()->toArray();

        $groupBys = collect($payload['groupBys'] ?? [])
            ->map(function ($gb) {
                $attr = $gb['attribute'] ?? $gb;
                if (empty($attr['column'])) return null;
                return new GroupBy(new Attribute($attr['modelClass'] ?? $attr['model'] ?? '', $attr['column'], $attr['type'] ?? 'string'));
            })->filter()->values()->toArray();

        $aggregates = collect($payload['aggregates'] ?? [])
            ->map(function ($agg) {
                $attr = $agg['attribute'] ?? $agg;
                if (empty($attr['column'])) return null;
                return new Aggregate(
                    new Attribute($attr['modelClass'] ?? $attr['model'] ?? '', $attr['column'], $attr['type'] ?? 'string'),
                    $agg['function'] ?? 'SUM',
                    $agg['alias'] ?? null
                );
            })->filter()->values()->toArray();

        $innerFilters = null;
        if (!empty($payload['innerFilters'])) {
            $innerFilters = self::parseFilterNode($payload['innerFilters']);
        }

        $outerFilters = null;
        if (!empty($payload['outerFilters'])) {
            $outerFilters = self::parseFilterNode($payload['outerFilters'], true);
        }

        $sorts = collect($payload['sorts'] ?? [])
            ->map(function ($sort) {
                $attr = $sort['attribute'] ?? $sort;
                if (empty($attr['column'])) return null;
                return new Sort(
                    new Attribute($attr['modelClass'] ?? $attr['model'] ?? '', $attr['column'], $attr['type'] ?? 'string'),
                    $sort['direction'] ?? 'ASC'
                );
            })->filter()->values()->toArray();

        $limit = isset($payload['limit']) ? (int) $payload['limit'] : null;

        return new ReportRequest(
            baseModel: $baseModel,
            targetModels: $targetModels,
            selectedAttributes: $selectedAttributes,
            innerFilters: $innerFilters,
            groupBys: $groupBys,
            aggregates: $aggregates,
            outerFilters: $outerFilters,
            sorts: $sorts,
            limit: $limit
        );
    }

    private static function parseFilterNode(array $node, bool $isOuter = false): ?FilterNode
    {
        if (isset($node['type']) && $node['type'] === 'group') {
            $children = collect($node['children'] ?? [])->map(function ($child) use ($isOuter) {
                return self::parseFilterNode($child, $isOuter);
            })->filter()->values()->toArray();

            if (empty($children)) {
                return null;
            }

            return new FilterGroup($node['logic'] ?? 'and', $children);
        }

        $attr = $node['attribute'] ?? $node;
        if (empty($attr['column'])) {
            return null;
        }

        return new FilterLeaf(
            new Attribute($attr['modelClass'] ?? $attr['model'] ?? '', $attr['column'], $attr['dataType'] ?? $attr['type'] ?? 'string', $attr['isVirtual'] ?? false),
            $node['operator'] ?? '=',
            $node['value'] ?? ''
        );
    }
}
