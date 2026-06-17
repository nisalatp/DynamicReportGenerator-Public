<?php
namespace Nisalatp\DynamicReportGenerator\Types;
readonly class ModelInfo {
    public function __construct(public string $modelClass, public string $table, public string $primaryKey, public array $casts = []) {}
}
