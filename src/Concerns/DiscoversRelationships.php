<?php

namespace Nisalatp\DynamicReportGenerator\Concerns;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use ReflectionClass;
use ReflectionMethod;
use Nisalatp\DynamicReportGenerator\Types\ModelLink;
use Nisalatp\DynamicReportGenerator\Types\JoinPlan;
use Nisalatp\DynamicReportGenerator\Types\JoinStep;
use Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerException;

/**
 * Relationship Graph — Bidirectional graph construction and BFS pathfinding.
 *
 * Handles the two-phase link discovery (forward reflection + reverse synthesis)
 * and the Breadth-First Search algorithm for auto-joining disparate tables.
 */
trait DiscoversRelationships
{
    /**
     * Discover all relationship links between allowed models (bidirectional).
     *
     * This is the core of the BFS graph construction. It first discovers forward
     * relationships (those explicitly declared on models via Eloquent methods),
     * then synthesizes reverse edges for any relationship that only has one
     * direction defined. The result is a fully bidirectional adjacency list.
     *
     * The graph is cached in-memory for the lifetime of this ReportMaker instance
     * to avoid repeated reflection overhead on subsequent calls.
     *
     * @return array<string, array<string, ModelLink>> Adjacency list: model => [model => link]
     */
    private function discoverLinks(): array
    {
        // Return cached graph if already computed (avoids repeated reflection)
        if ($this->cachedLinks !== null) {
            return $this->cachedLinks;
        }

        // Phase 1: Discover forward-declared relationships via reflection.
        // These are the edges that developers explicitly defined on their models
        // (e.g., Order::user() returns BelongsTo, User::orders() returns HasMany).
        $forwardLinks = $this->getForwardRelations();

        // Phase 2: Synthesize reverse edges for any one-directional relationships.
        // If Model A declares a relationship to Model B but Model B does not declare
        // the inverse, we infer the reverse edge so BFS can traverse in both directions.
        // This is critical — without it, BFS can only follow the direction in which
        // relationships were declared, missing valid join paths in the opposite direction.
        $allLinks = $this->getReverseRelations($forwardLinks);

        $this->cachedLinks = $allLinks;
        return $allLinks;
    }

    /**
     * Forward relation discovery: scan each allowed model's public methods via
     * reflection to find declared Eloquent relationships.
     *
     * Supported types: BelongsTo, HasOne, HasMany, BelongsToMany.
     *
     * Each discovered relationship becomes a directed edge in the graph:
     *   ModelA --[relationType]--> ModelB
     *
     * For example, if Order has a `user()` method returning BelongsTo(User),
     * we record: Order --[BelongsTo]--> User, with foreignKey='user_id', localKey='id'.
     *
     * @return array<string, array<string, ModelLink>>
     */
    private function getForwardRelations(): array
    {
        $links = [];
        $supportedTypes = [
            'BelongsTo' => BelongsTo::class,
            'HasOne' => HasOne::class,
            'HasMany' => HasMany::class,
            'BelongsToMany' => BelongsToMany::class,
        ];

        foreach (array_keys($this->allowedModels) as $modelClass) {
            $reflection = new ReflectionClass($modelClass);
            $instance = new $modelClass();

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getNumberOfRequiredParameters() > 0 || $method->class !== $modelClass)
                    continue;

                try {
                    $relation = $method->invoke($instance);
                    if ($relation instanceof Relation) {
                        $type = null;
                        foreach ($supportedTypes as $relationName => $relationClass) {
                            if ($relation instanceof $relationClass) {
                                $type = $relationName;
                                break;
                            }
                        }
                        if (!$type)
                            continue;

                        $toModel = get_class($relation->getRelated());
                        if (!isset($this->allowedModels[$toModel]))
                            continue;

                        $foreignKey = '';
                        $localKey = '';
                        if ($relation instanceof BelongsTo) {
                            $foreignKey = $relation->getForeignKeyName();
                            $localKey = $relation->getOwnerKeyName();
                        } elseif ($relation instanceof HasOne || $relation instanceof HasMany) {
                            $foreignKey = $relation->getForeignKeyName();
                            $localKey = $relation->getLocalKeyName();
                        } elseif ($relation instanceof BelongsToMany) {
                            $foreignKey = $relation->getForeignPivotKeyName();
                            $localKey = $relation->getRelatedPivotKeyName();
                        }

                        if (!isset($links[$modelClass]))
                            $links[$modelClass] = [];
                        $links[$modelClass][$toModel] = new ModelLink(
                            fromModel: $modelClass,
                            toModel: $toModel,
                            type: $type,
                            foreignKey: $foreignKey,
                            localKey: $localKey,
                            methodName: $method->getName(),
                            direction: 'forward',
                        );
                    }
                } catch (\Throwable $e) {
                }
            }
        }
        return $links;
    }

    /**
     * Reverse relation synthesis: for every forward edge A→B, check if the
     * inverse edge B→A already exists. If not, synthesize one by inverting
     * the relationship type and reusing the same foreign/local keys.
     *
     * Why this is necessary:
     * Eloquent models often only declare one direction of a relationship.
     * For example, OrderItem might have `product()` returning BelongsTo(Product),
     * but Product might not define `orderItems()` returning HasMany(OrderItem).
     * Without reverse edges, the BFS pathfinder cannot traverse from Product
     * to OrderItem, making it impossible to discover join paths that start
     * from Product.
     *
     * In a family-relationship context, if a Person model stores `father_id`
     * and `mother_id` as BelongsTo relationships, the forward edges go from
     * child to parent. Without reverse synthesis, BFS cannot traverse from
     * parent to child — blocking discovery of grandchildren, siblings,
     * uncles, cousins, and all "downward" relationship paths.
     *
     * Relationship type inversion rules:
     *   BelongsTo    → HasMany   (child→parent becomes parent→children)
     *   HasMany      → BelongsTo (parent→children becomes child→parent)
     *   HasOne       → BelongsTo (parent→child becomes child→parent)
     *   BelongsToMany→ BelongsToMany (swap pivot keys)
     *
     * Key handling:
     *   For BelongsTo/HasOne/HasMany inversions, the foreign and local keys
     *   stay identical — the join builder already handles BelongsTo key
     *   swapping in buildInnerQuery(). For BelongsToMany, the pivot keys
     *   are swapped since the "owning" side flips.
     *
     * @param array<string, array<string, ModelLink>> $forwardLinks The forward-only graph.
     * @return array<string, array<string, ModelLink>> The merged bidirectional graph.
     */
    private function getReverseRelations(array $forwardLinks): array
    {
        $allLinks = $forwardLinks;

        foreach ($forwardLinks as $fromModel => $targets) {
            foreach ($targets as $toModel => $link) {
                // If the developer already declared both directions, respect that.
                // Don't overwrite an explicit definition with a synthesized one.
                if (isset($allLinks[$toModel][$fromModel])) {
                    continue;
                }

                // Invert the relationship type to get the correct reverse edge.
                // BelongsTo (child→parent) becomes HasMany (parent→children).
                // HasMany/HasOne (parent→child) becomes BelongsTo (child→parent).
                // BelongsToMany is symmetric but needs swapped pivot keys.
                $reverseType = match ($link->type) {
                    'BelongsTo' => 'HasMany',
                    'HasOne', 'HasMany' => 'BelongsTo',
                    'BelongsToMany' => 'BelongsToMany',
                    default => null,
                };

                if ($reverseType === null) {
                    continue;
                }

                // For BelongsToMany, swap the pivot keys since the "owning" side flips.
                // For all other types, the foreign/local keys stay the same because
                // buildInnerQuery() already handles the BelongsTo key swap at JOIN time.
                $reverseForeignKey = $link->type === 'BelongsToMany' ? $link->localKey : $link->foreignKey;
                $reverseLocalKey = $link->type === 'BelongsToMany' ? $link->foreignKey : $link->localKey;

                if (!isset($allLinks[$toModel])) {
                    $allLinks[$toModel] = [];
                }

                $allLinks[$toModel][$fromModel] = new ModelLink(
                    fromModel: $toModel,
                    toModel: $fromModel,
                    type: $reverseType,
                    foreignKey: $reverseForeignKey,
                    localKey: $reverseLocalKey,
                    methodName: $link->methodName . '_reverse',
                    direction: 'reverse',
                );
            }
        }

        return $allLinks;
    }

    /**
     * Build the join plan by resolving BFS shortest paths from the base model
     * to each target model, then converting each path edge into a JoinStep.
     *
     * Thanks to bidirectional link discovery, this can now resolve paths that
     * traverse relationships in either direction — even when only one side
     * of the relationship was explicitly declared on the model.
     */
    private function planJoins(string $base, array $targets, array $links, string $aliasPrefix = 't'): JoinPlan
    {
        $steps = [];
        $aliasCounter = 1;

        foreach ($targets as $target) {
            if ($base === $target)
                continue;

            $path = $this->findShortestPath($base, $target, $links);
            if (!$path)
                throw ReportMakerException::noPath($base, $target);

            for ($i = 0; $i < count($path) - 1; $i++) {
                $sourceModel = $path[$i];
                $targetModel = $path[$i + 1];
                $link = $links[$sourceModel][$targetModel];

                // Propagate the link's direction (forward/reverse) into the JoinStep
                // so the join plan carries full traversal metadata for debugging
                // and for the frontend's join plan visualizer.
                $steps[] = new JoinStep(
                    fromModel: $sourceModel,
                    toModel: $targetModel,
                    joinType: 'left',
                    localTableAlias: $i === 0 ? $aliasPrefix . '0' : $aliasPrefix . ($aliasCounter - 1),
                    remoteTableAlias: $aliasPrefix . $aliasCounter,
                    localKey: $link->localKey,
                    foreignKey: $link->foreignKey,
                    relationType: $link->type,
                    direction: $link->direction,
                );
                $aliasCounter++;
            }
        }
        return new JoinPlan($steps);
    }

    /**
     * BFS shortest-path finder between two models in the relationship graph.
     *
     * Traverses the bidirectional adjacency list built by discoverLinks() to
     * find the shortest sequence of model hops from $start to $end. Each model
     * class can appear at most once in the path (standard BFS visited guard),
     * which prevents cycles through spouse loops, self-referencing models, or
     * any other circular relationship patterns.
     *
     * The visited set tracks model class names — since each class is unique in
     * the graph, this is sufficient to prevent infinite loops without needing
     * path-signature-based tracking. Path signatures would only be needed for
     * person-instance-level traversal (where the same Person model class
     * connects to itself via parent/child/spouse edges).
     *
     * @param string $start Starting model class.
     * @param string $end   Target model class.
     * @param array  $links Bidirectional adjacency list from discoverLinks().
     * @return array|null Array of model class names forming the shortest path, or null.
     */
    private function findShortestPath(string $start, string $end, array $links): ?array
    {
        $queue = [[$start]];
        $visited = [$start => true];

        while (!empty($queue)) {
            $path = array_shift($queue);
            $current = end($path);

            if ($current === $end)
                return $path;

            // Iterate all neighbors — this now includes both forward-declared
            // and reverse-synthesized edges, enabling bidirectional traversal.
            $neighbors = $links[$current] ?? [];
            foreach ($neighbors as $neighborClass => $link) {
                if (!isset($visited[$neighborClass])) {

                    $visited[$neighborClass] = true;
                    $newPath = $path;
                    $newPath[] = $neighborClass;
                    $queue[] = $newPath;
                }
            }
        }
        return null;
    }
}
