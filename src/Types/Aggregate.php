<?php
namespace Nisalatp\DynamicReportGenerator\Types;

use InvalidArgumentException;

/**
 * Aggregate DTO.
 *
 * Represents an aggregation function (e.g., SUM, COUNT) applied to a specific attribute.
 */
readonly class Aggregate
{
    public const ALLOWED_FUNCTIONS = ['SUM', 'AVG', 'COUNT', 'MIN', 'MAX'];

    public function __construct(
        public Attribute $attribute,
        public string $function,
        public ?string $alias = null
    ) {
        $upperFunc = strtoupper($function);

        if (!in_array($upperFunc, self::ALLOWED_FUNCTIONS, true)) {
            throw new InvalidArgumentException(
                "Invalid aggregate function: {$function}. Allowed functions are: " .
                implode(', ', self::ALLOWED_FUNCTIONS)
            );
        }
    }

    public function toArray(): array
    {
        return [
            'attribute' => $this->attribute->toArray(),
            'function' => strtoupper($this->function),
            'alias' => $this->alias,
        ];
    }
}
