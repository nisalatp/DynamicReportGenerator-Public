<?php
namespace Nisalatp\DynamicReportGenerator\Types;
use Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerException;
readonly class FilterLeaf implements FilterNode {
    private const ALLOWED_OPERATORS = ['=' => true, '!=' => true, '>' => true, '>=' => true, '<' => true, '<=' => true, 'like' => true, 'in' => true, 'between' => true, 'is null' => true, 'is not null' => true];
    public string $operator;
    public function __construct(public Attribute $attribute, string $operator, public mixed $value = null) {
        $this->operator = strtolower(trim($operator));
        if (!isset(self::ALLOWED_OPERATORS[$this->operator])) throw ReportMakerException::badFilter($this->operator);
        if ($this->operator === 'like' && $this->attribute->type !== 'string' && $this->attribute->type !== 'text') throw ReportMakerException::badFilterType($this->operator, $this->attribute->type);
        if ($this->operator === 'in' && !is_array($this->value)) throw ReportMakerException::badFilterValue($this->operator);
        if ($this->operator === 'between' && (!is_array($this->value) || count($this->value) !== 2)) throw ReportMakerException::badFilterValue($this->operator);
    }

    public function toArray(): array {
        return [
            'type' => 'leaf',
            'attribute' => $this->attribute->toArray(),
            'operator' => $this->operator,
            'value' => $this->value,
        ];
    }
}
