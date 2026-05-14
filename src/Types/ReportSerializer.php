<?php
namespace Nisalatp\DynamicReportGenerator\Types;

use Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerException;

class ReportSerializer {
    public function toJson(ReportRequest $request): string {
        $data = [
            'baseModel' => $request->baseModel,
            'targetModels' => $request->targetModels,
            'selectedAttributes' => array_map(fn($attr) => $attr->toArray(), $request->selectedAttributes),
            'innerFilters' => $request->innerFilters ? $request->innerFilters->toArray() : null,
            'groupBys' => array_map(fn($gb) => $gb->toArray(), $request->groupBys),
            'aggregates' => array_map(fn($agg) => $agg->toArray(), $request->aggregates),
            'outerFilters' => $request->outerFilters ? $request->outerFilters->toArray() : null,
            'sorts' => array_map(fn($sort) => $sort->toArray(), $request->sorts),
        ];

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    public function fromJson(string $json): ReportRequest {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($data['baseModel'])) {
            throw new \InvalidArgumentException("Missing baseModel in serialized report request.");
        }

        return new ReportRequest(
            baseModel: $data['baseModel'],
            targetModels: $data['targetModels'] ?? [],
            selectedAttributes: array_map(fn($attr) => $this->parseAttribute($attr), $data['selectedAttributes'] ?? []),
            innerFilters: isset($data['innerFilters']) ? $this->parseFilterNode($data['innerFilters']) : null,
            groupBys: array_map(fn($gb) => new GroupBy($this->parseAttribute($gb['attribute'])), $data['groupBys'] ?? []),
            aggregates: array_map(fn($agg) => new Aggregate(
                $this->parseAttribute($agg['attribute']), 
                $agg['function'], 
                $agg['alias'] ?? null
            ), $data['aggregates'] ?? []),
            outerFilters: isset($data['outerFilters']) ? $this->parseFilterNode($data['outerFilters']) : null,
            sorts: array_map(fn($sort) => new Sort(
                $this->parseAttribute($sort['attribute']),
                $sort['direction'] ?? 'ASC'
            ), $data['sorts'] ?? [])
        );
    }

    private function parseAttribute(array $data): Attribute {
        return new Attribute(
            modelClass: $data['modelClass'],
            column: $data['column'],
            type: $data['type'],
            isVirtual: $data['isVirtual'] ?? false,
            jsonPath: $data['jsonPath'] ?? null,
            alias: $data['alias'] ?? null
        );
    }

    public function parseFilterNode(array $data): FilterNode {
        if (!isset($data['type'])) {
            throw new \InvalidArgumentException("Filter node missing type");
        }

        if ($data['type'] === 'group') {
            $children = array_map(fn($child) => $this->parseFilterNode($child), $data['children'] ?? []);
            return new FilterGroup($data['logic'] ?? 'and', $children);
        } elseif ($data['type'] === 'leaf') {
            return new FilterLeaf(
                $this->parseAttribute($data['attribute']),
                $data['operator'],
                $data['value'] ?? null
            );
        }

        throw new \InvalidArgumentException("Unknown filter node type: " . $data['type']);
    }
}
