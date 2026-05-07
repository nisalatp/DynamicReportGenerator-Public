<?php
namespace Nisalatp\DynamicReportGenerator\Types;
class ReportRequest {
    public function __construct(public readonly string $baseModel, public readonly array $targetModels = [], public readonly array $selectedAttributes = [], public readonly ?FilterNode $innerFilters = null, public readonly array $groupBys = [], public readonly array $aggregates = [], public readonly ?FilterNode $outerFilters = null) {}

    public function toJson(): string {
        return (new ReportSerializer())->toJson($this);
    }

    public static function fromJson(string $json): self {
        return (new ReportSerializer())->fromJson($json);
    }
}
