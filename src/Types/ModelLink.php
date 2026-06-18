<?php

namespace Nisalatp\DynamicReportGenerator\Types;

/**
 * Represents a single directed edge in the model relationship graph.
 *
 * Each link connects two Eloquent models and records the relationship type,
 * key columns, and the Eloquent method that declared it. The 'direction'
 * field indicates whether this edge was discovered via forward reflection
 * ('forward') or synthesized as the inverse of another edge ('reverse').
 *
 * Reverse edges are essential for bidirectional BFS traversal — without
 * them, the pathfinder can only follow relationships in the direction
 * they were explicitly declared on the model, missing valid join paths
 * in the opposite direction.
 */
readonly class ModelLink
{
    public function __construct(
        public string $fromModel,
        public string $toModel,
        public string $type,
        public string $foreignKey,
        public string $localKey,
        public string $methodName,
        public string $direction = 'forward',
        /**
         * Only set for BelongsToMany relationships.
         * Holds the name of the pivot/junction table (e.g. 'role_user') so that
         * planJoins() can emit the two JOIN steps required by a many-to-many edge:
         *   base_table  JOIN pivot_table ON base_table.localKey = pivot_table.foreignKey
         *   pivot_table JOIN related_table ON pivot_table.localKey = related_table.pk
         */
        public ?string $pivotTable = null,
    ) {}
}
