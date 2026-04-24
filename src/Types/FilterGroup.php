<?php
namespace Nisalatp\DynamicReportGenerator\Types;
readonly class FilterGroup implements FilterNode {
    public string $logic;
    public function __construct(string $logic, public array $children = []) { $this->logic = strtolower($logic) === 'or' ? 'or' : 'and'; }
}
