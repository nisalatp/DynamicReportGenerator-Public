<?php

namespace Nisalatp\DynamicReportGenerator\Types;

/**
 * A single step in a resolved join plan.
 *
 * Records which models are being joined, the table aliases used in SQL,
 * the key columns for the JOIN condition, the Eloquent relation type,
 * and the traversal direction ('forward' = explicitly declared,
 * 'reverse' = synthesized inverse edge).
 */
readonly class JoinStep
{
    public function __construct(
        public string $fromModel,
        public string $toModel,
        public string $joinType,
        public string $localTableAlias,
        public string $remoteTableAlias,
        public string $localKey,
        public string $foreignKey,
        public string $relationType,
        public string $direction = 'forward',
    ) {}
}

