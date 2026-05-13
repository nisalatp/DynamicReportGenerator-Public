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
                return new Attribute($attr['model'], $attr['column'], $attr['type'] ?? 'string', false, null, $attr['alias'] ?? null);
            })->values()->toArray();

        $groupBys = collect($payload['groupBys'] ?? [])
            ->filter(fn($gb) => !empty($gb['column']))
            ->map(function ($gb) {
                return new GroupBy(new Attribute($gb['model'], $gb['column'], $gb['type'] ?? 'string'));
            })->values()->toArray();

        $aggregates = collect($payload['aggregates'] ?? [])
            ->filter(fn($agg) => !empty($agg['column']))
            ->map(function ($agg) {
                return new Aggregate(
                    new Attribute($agg['model'], $agg['column'], $agg['type'] ?? 'string'),
                    $agg['function'],
                    $agg['alias'] ?? null
                );
            })->values()->toArray();

        $innerFilters = null;
        if (!empty($payload['innerFilters'])) {
            if (isset($payload['innerFilters']['type']) && $payload['innerFilters']['type'] === 'group') {
                $innerFilters = self::parseFilterNode($payload['innerFilters']);
            } else {
                $leaves = collect($payload['innerFilters'])
                    ->filter(fn($f) => !empty($f['column']))
                    ->map(function ($f) {
                        return new FilterLeaf(
                            new Attribute($f['model'], $f['column'], $f['type'] ?? 'string'),
                            $f['operator'],
                            $f['value']
                        );
                    })->values()->toArray();

                if (count($leaves) > 1) {
                    $innerFilters = new FilterGroup('and', $leaves);
                } elseif (count($leaves) === 1) {
                    $innerFilters = $leaves[0];
                }
            }
        }

        $outerFilters = null;
        if (!empty($payload['outerFilters'])) {
            if (isset($payload['outerFilters']['type']) && $payload['outerFilters']['type'] === 'group') {
                $outerFilters = self::parseFilterNode($payload['outerFilters'], true);
            } else {
                $leaves = collect($payload['outerFilters'])
                    ->filter(fn($f) => !empty($f['column']))
                    ->map(function ($f) {
                        return new FilterLeaf(
                            new Attribute($f['model'] ?? '', $f['column'], $f['type'] ?? 'string', true),
                            $f['operator'],
                            $f['value']
                        );
                    })->values()->toArray();

                if (count($leaves) > 1) {
                    $outerFilters = new FilterGroup('and', $leaves);
                } elseif (count($leaves) === 1) {
                    $outerFilters = $leaves[0];
                }
            }
        }

        $sorts = collect($payload['sorts'] ?? [])
            ->filter(fn($sort) => !empty($sort['column']))
            ->map(function ($sort) {
                return new Sort(
                    new Attribute($sort['model'], $sort['column'], $sort['type'] ?? 'string'),
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

        if (empty($node['column'])) {
            return null;
        }

        return new FilterLeaf(
            new Attribute($node['model'] ?? '', $node['column'], $node['dataType'] ?? 'string', $isOuter),
            $node['operator'],
            $node['value'] ?? null
        );
    }
}
