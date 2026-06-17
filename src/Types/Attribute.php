<?php
namespace Nisalatp\DynamicReportGenerator\Types;
readonly class Attribute {
    public function __construct(public string $modelClass, public string $column, public string $type, public bool $isVirtual = false, public ?string $jsonPath = null, public ?string $alias = null) {}

    public function toArray(): array {
        return [
            'modelClass' => $this->modelClass,
            'column' => $this->column,
            'type' => $this->type,
            'isVirtual' => $this->isVirtual,
            'jsonPath' => $this->jsonPath,
            'alias' => $this->alias,
        ];
    }
}
