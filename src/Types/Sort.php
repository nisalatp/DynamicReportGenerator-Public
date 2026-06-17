<?php
namespace Nisalatp\DynamicReportGenerator\Types;

use InvalidArgumentException;

readonly class Sort {
    public const ALLOWED_DIRECTIONS = ['ASC', 'DESC'];

    public function __construct(public Attribute $attribute, public string $direction = 'ASC') {
        $upperDir = strtoupper($direction);
        if (!in_array($upperDir, self::ALLOWED_DIRECTIONS, true)) {
            throw new InvalidArgumentException("Invalid sort direction: {$direction}. Allowed directions are: ASC, DESC");
        }
    }

    public function toArray(): array {
        return [
            'attribute' => $this->attribute->toArray(),
            'direction' => strtoupper($this->direction),
        ];
    }
}
