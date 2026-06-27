<?php
namespace Nisalatp\DynamicReportGenerator\Types;
/**
 * Filter Group Node.
 *
 * Represents a logical grouping (AND/OR) of other Filter Nodes (either Leaves or other Groups).
 * This allows the AST to model complex, nested boolean logic.
 */
readonly class FilterGroup implements FilterNode
{

    public string $logic;

    public function __construct(string $logic, public array $children = [])
    {
        $this->logic = strtolower($logic) === 'or' ? 'or' : 'and';
    }

    public function toArray(): array
    {
        return [
            'type' => 'group',
            'logic' => $this->logic,
            'children' => array_map(function (FilterNode $child) {
                return $child->toArray();
            }, $this->children),
        ];
    }
}
