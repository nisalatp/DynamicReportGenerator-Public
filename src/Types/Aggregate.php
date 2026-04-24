<?php
namespace Nisalatp\DynamicReportGenerator\Types;
readonly class Aggregate {
    public function __construct(public Attribute $attribute, public string $function, public ?string $alias = null) {}
}
