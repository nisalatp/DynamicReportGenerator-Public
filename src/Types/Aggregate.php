<?php
namespace Nisalatp\DynamicReportGenerator\Types;
readonly class Aggregate {
    public function __construct(public Attribute $attribute, public string $function, public ?string $alias = null) {}

    public function toArray(): array {
        return [
            'attribute' => $this->attribute->toArray(),
            'function' => $this->function,
            'alias' => $this->alias,
        ];
    }
}
