<?php
namespace Nisalatp\DynamicReportGenerator\Types;
readonly class GroupBy
{
    public function __construct(public Attribute $attribute)
    {
    }

    public function toArray(): array
    {
        return [
            'attribute' => $this->attribute->toArray(),
        ];
    }
}
