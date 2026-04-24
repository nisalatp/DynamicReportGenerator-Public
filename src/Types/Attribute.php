<?php
namespace Nisalatp\DynamicReportGenerator\Types;
readonly class Attribute {
    public function __construct(public string $modelClass, public string $column, public string $type, public bool $isVirtual = false, public ?string $jsonPath = null) {}
}
