<?php
namespace Nisalatp\DynamicReportGenerator\Types;
readonly class FilterGroup implements FilterNode {
    public string $logic;
    public function __construct(string $logic, public array $children = []) { $this->logic = strtolower($logic) === 'or' ? 'or' : 'and'; }

    public function toArray(): array {
        return [
            'type' => 'group',
            'logic' => $this->logic,
            'children' => array_map(fn(FilterNode $child) => $child->toArray(), $this->children),
        ];
    }
}
