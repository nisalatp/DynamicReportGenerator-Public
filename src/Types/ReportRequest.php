<?php
namespace Nisalatp\DynamicReportGenerator\Types;
/**
 * Report Request (AST Root).
 *
 * This is the central, immutable Data Transfer Object (DTO) that represents
 * the entire parsed Abstract Syntax Tree (AST) of a user's report query.
 * It is passed into the core Engine to be compiled into raw SQL.
 */
class ReportRequest {
    public function __construct(
        public readonly string $baseModel,
        public readonly array $targetModels = [],
        public readonly array $selectedAttributes = [],
        public readonly ?FilterNode $innerFilters = null,
        public readonly array $groupBys = [],
        public readonly array $aggregates = [],
        public readonly ?FilterNode $outerFilters = null,
        public readonly array $sorts = []
    ) {}

    public function toJson(): string {
        return (new ReportSerializer())->toJson($this);
    }

    public static function fromJson(string $json): self {
        return (new ReportSerializer())->fromJson($json);
    }
}
