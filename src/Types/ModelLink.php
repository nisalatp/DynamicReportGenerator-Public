<?php
namespace Nisalatp\DynamicReportGenerator\Types;
readonly class ModelLink {
    public function __construct(public string $fromModel, public string $toModel, public string $type, public string $foreignKey, public string $localKey, public string $methodName) {}
}
