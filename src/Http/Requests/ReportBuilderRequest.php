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

class ReportBuilderRequest
{
    /**
     * Parse a raw frontend JSON payload into a strict ReportRequest AST DTO.
     */
    public static function fromPayload(array $payload): ReportRequest
    {
        $baseModel = $payload['baseModel'];
        $targetModels = $payload['targetModels'] ?? [];

        $selectedAttributes = collect($payload['selectedAttributes'] ?? [])
            ->filter(fn($attr) => !empty($attr['column']))
            ->map(function ($attr) {
                return new Attribute($attr['modelClass'], $attr['column'], $attr['type'] ?? 'string', false, null, $attr['alias'] ?? null);
            })->values()->toArray();

        $groupBys = collect($payload['groupBys'] ?? [])
            ->filter(fn($gb) => isset($gb['attribute']) && !empty($gb['attribute']['column']))
            ->map(function ($gb) {
                return new GroupBy(new Attribute($gb['attribute']['modelClass'], $gb['attribute']['column'], $gb['attribute']['type'] ?? 'string'));
            })->values()->toArray();

        $aggregates = collect($payload['aggregates'] ?? [])
            ->filter(fn($agg) => isset($agg['attribute']) && !empty($agg['attribute']['column']))
            ->map(function ($agg) {
                return new Aggregate(
                    new Attribute($agg['attribute']['modelClass'], $agg['attribute']['column'], $agg['attribute']['type'] ?? 'string'),
                    $agg['function'],
                    $agg['alias'] ?? null
                );
            })->values()->toArray();

        $innerFilters = null;
        if (!empty($payload['innerFilters'])) {
            $innerFilters = self::parseFilterNode($payload['innerFilters']);
        }

        $outerFilters = null;
        if (!empty($payload['outerFilters'])) {
            $outerFilters = self::parseFilterNode($payload['outerFilters'], true);
        }

        $sorts = collect($payload['sorts'] ?? [])
            ->filter(fn($sort) => isset($sort['attribute']) && !empty($sort['attribute']['column']))
            ->map(function ($sort) {
                return new Sort(
                    new Attribute($sort['attribute']['modelClass'], $sort['attribute']['column'], $sort['attribute']['type'] ?? 'string'),
                    $sort['direction'] ?? 'ASC'
                );
            })->values()->toArray();

        return new ReportRequest(
            baseModel: $baseModel,
            targetModels: $targetModels,
            selectedAttributes: $selectedAttributes,
            innerFilters: $innerFilters,
            groupBys: $groupBys,
            aggregates: $aggregates,
            outerFilters: $outerFilters,
            sorts: $sorts
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

        if (!isset($node['attribute']) || empty($node['attribute']['column'])) {
            return null;
        }

        return new FilterLeaf(
            new Attribute($node['attribute']['modelClass'] ?? '', $node['attribute']['column'], $node['attribute']['type'] ?? 'string', $isOuter),
            $node['operator'],
            $node['value'] ?? null
        );
    }
}
