<?php

namespace Nisalatp\DynamicReportGenerator\Concerns;

use Illuminate\Database\Eloquent\Model;
use Nisalatp\DynamicReportGenerator\Types\ReportRequest;
use Nisalatp\DynamicReportGenerator\Types\Attribute;
use Nisalatp\DynamicReportGenerator\Types\FilterNode;
use Nisalatp\DynamicReportGenerator\Types\FilterGroup;
use Nisalatp\DynamicReportGenerator\Types\FilterLeaf;
use Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerException;
use Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerSecurityException;
use Nisalatp\DynamicReportGenerator\Models\RestrictedModel;
use Nisalatp\DynamicReportGenerator\Models\AttributeRestriction;
use Nisalatp\DynamicReportGenerator\Contracts\DynamicReportSubject;

/**
 * Security Enforcement — ALS resolution, validation, model/attribute restriction.
 *
 * Handles the engine's defense-in-depth: resolving attribute restrictions for
 * the current user, validating the AST against blocked rules, enforcing filter
 * depth limits, and CRUD operations on model/attribute restriction records.
 */
trait EnforcesSecurity
{
    /**
     * Resolve all attribute restrictions for the given execution subjects.
     */
    private function resolveAttributeRestrictions(?array $subjects): void
    {
        $this->resolvedRestrictions = [];

        if ($subjects === null && function_exists('auth') && auth()->check()) {
            $user = auth()->user();
            if ($user instanceof DynamicReportSubject) {
                $subjects = $user->getDynamicReportSubjects();
            } else {
                $subjects = [$user];
            }
        }

        // Guard: if $subjects was null (not explicitly provided) and auth fallback
        // didn't resolve anyone, refuse to run. This prevents reports from executing
        // without an identity context (e.g., unauthenticated requests, queue jobs
        // without explicit subjects). Callers that intentionally want no restrictions
        // should pass an empty array [] instead of null.
        if ($subjects === null) {
            throw new ReportMakerSecurityException(
                'Cannot generate reports without an authenticated user or explicit subjects. ' .
                'Pass subjects explicitly or ensure an authenticated session exists.'
            );
        }

        if (empty($subjects)) {
            return;
        }

        $query = AttributeRestriction::query();
        
        $query->where(function ($queryBuilder) use ($subjects) {
            foreach ($subjects as $subject) {
                if ($subject instanceof Model) {
                    $queryBuilder->orWhere(function ($subQuery) use ($subject) {
                        $subQuery->where('subject_type', get_class($subject))
                                 ->where('subject_id', $subject->getKey());
                    });
                }
            }
        });

        try {
            $restrictions = $query->get();
            foreach ($restrictions as $r) {
                $key = $r->model_class . '.' . $r->attribute;
                // Blocked takes precedence over masked
                if (!isset($this->resolvedRestrictions[$key]) || $this->resolvedRestrictions[$key] !== 'blocked') {
                    $this->resolvedRestrictions[$key] = $r->restriction_type;
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Gracefully handle table-not-found during initial setup (before migrations run).
            // Any other query exception (connection failure, syntax error, etc.) is re-thrown
            // to prevent ALS from silently degrading to zero restrictions.
            $tableNotFoundCodes = ['42S02', '42P01', 'HY000']; // MySQL, PostgreSQL, SQLite
            if (!in_array($e->getCode(), $tableNotFoundCodes, false)) {
                throw $e;
            }
        }
    }

    /**
     * Get the restriction type for a specific attribute.
     */
    private function getRestrictionType(string $modelClass, string $attribute, bool $isVirtual = false): ?string
    {
        if ($isVirtual && !str_starts_with($attribute, 'va:')) {
            $attribute = 'va:' . $attribute;
        }
        return $this->resolvedRestrictions[$modelClass . '.' . $attribute] ?? null;
    }

    /**
     * Extract all Attribute objects from a filter tree (recursive).
     */
    private function extractAttributesFromFilter(FilterNode $node): array
    {
        if ($node instanceof FilterLeaf) {
            return [$node->attribute];
        }
        if ($node instanceof FilterGroup) {
            $attrs = [];
            foreach ($node->children as $child) {
                $attrs = array_merge($attrs, $this->extractAttributesFromFilter($child));
            }
            return $attrs;
        }
        return [];
    }

    /**
     * Validate that no blocked attributes are used in active computation positions.
     */
    private function validateSecurity(ReportRequest $req): void
    {
        $checkBlocked = function (array $attributes, string $context) {
            foreach ($attributes as $attr) {
                if ($this->getRestrictionType($attr->modelClass, $attr->column, $attr->isVirtual) === 'blocked') {
                    throw new ReportMakerSecurityException("Attribute {$attr->modelClass}.{$attr->column} is BLOCKED and cannot be used in {$context} calculations.");
                }
            }
        };

        if ($req->innerFilters) {
            $checkBlocked($this->extractAttributesFromFilter($req->innerFilters), 'filters');
        }
        if ($req->outerFilters) {
            $checkBlocked($this->extractAttributesFromFilter($req->outerFilters), 'filters');
        }
        if ($req->groupBys) {
            $groupByAttributes = array_map(fn($group) => $group->attribute, $req->groupBys);
            $checkBlocked($groupByAttributes, 'group bys');
        }
        
        if ($req->aggregates) {
            $aggregateAttributes = array_map(fn($aggregate) => $aggregate->attribute, $req->aggregates);
            $checkBlocked($aggregateAttributes, 'aggregates');
        }
        
        if ($req->sorts) {
            $sortAttributes = array_map(fn($sort) => $sort->attribute, $req->sorts);
            $checkBlocked($sortAttributes, 'sorts');
        }
    }

    /**
     * Validate that filter nesting depth does not exceed the configured limit.
     *
     * Deeply nested AND/OR groups can produce complex SQL and are a potential
     * abuse vector. This guard enforces the 'ui.max_filter_depth' config,
     * which should also be respected by frontend UIs via getMaxFilterDepth().
     *
     * @param FilterNode|null $node The root filter node to validate.
     * @param string $context 'WHERE' or 'HAVING', used in the error message.
     * @param int $currentDepth Internal recursion tracker.
     */
    private function validateFilterDepth(?FilterNode $node, string $context = 'WHERE', int $currentDepth = 0): void
    {
        if ($node === null) {
            return;
        }

        $maxDepth = $this->getMaxFilterDepth();

        if ($node instanceof FilterGroup) {
            $currentDepth++;
            if ($currentDepth > $maxDepth) {
                throw new ReportMakerException(
                    "Filter nesting in {$context} clause exceeds the maximum allowed depth of {$maxDepth}. "
                    . "Please simplify your filter structure."
                );
            }
            foreach ($node->children as $child) {
                $this->validateFilterDepth($child, $context, $currentDepth);
            }
        }
        // FilterLeaf nodes don't add depth — they are terminals.
    }

    /**
     * Schema Discovery: Get the list of explicitly restricted model classes.
     *
     * @return array Array of restricted model class names.
     */
    public function getRestrictedModels(): array
    {
        if ($this->restrictedModels !== null) {
            return $this->restrictedModels;
        }

        try {
            $this->restrictedModels = RestrictedModel::pluck('model_class')->toArray();
        } catch (\Exception $e) {
            // Fallback if table doesn't exist yet (e.g., during testing or before migration)
            $this->restrictedModels = [];
        }

        return $this->restrictedModels;
    }

    /**
     * Schema Discovery: Explicitly restrict a model from being used in the report generator.
     *
     * @param string $modelClass The fully qualified class name of the model to restrict.
     * @param int|null $actionByUserId Optional user ID for auditing.
     * @return void
     */
    public function restrictModel(string $modelClass, ?int $actionByUserId = null): void
    {
        RestrictedModel::firstOrCreate([
            'model_class' => $modelClass,
        ], [
            'restricted_by' => $actionByUserId,
        ]);

        // Invalidate caches
        $this->restrictedModels = null;
        $this->allowedModels = null;
        $this->cachedLinks = null;
    }

    /**
     * Schema Discovery: Remove a restriction from a model.
     *
     * @param string $modelClass The fully qualified class name of the model to unrestrict.
     * @return void
     */
    public function unrestrictModel(string $modelClass): void
    {
        RestrictedModel::where('model_class', $modelClass)->delete();

        // Invalidate caches
        $this->restrictedModels = null;
        $this->allowedModels = null;
        $this->cachedLinks = null;
    }

    /**
     * ALS Management: Restrict an attribute for a specific subject.
     */
    public function restrictAttribute(string $modelClass, string $attribute, \Illuminate\Database\Eloquent\Model $subject, string $type = 'masked'): void
    {
        AttributeRestriction::updateOrCreate([
            'model_class' => $modelClass,
            'attribute' => $attribute,
            'subject_type' => get_class($subject),
            'subject_id' => $subject->getKey(),
        ], [
            'restriction_type' => $type,
            'created_by' => function_exists('auth') && auth()->check() ? auth()->id() : null,
        ]);
        $this->resolvedRestrictions = []; // clear cache
    }

    /**
     * ALS Management: Remove a restriction.
     */
    public function unrestrictAttribute(string $modelClass, string $attribute, \Illuminate\Database\Eloquent\Model $subject): void
    {
        AttributeRestriction::where([
            'model_class' => $modelClass,
            'attribute' => $attribute,
            'subject_type' => get_class($subject),
            'subject_id' => $subject->getKey(),
        ])->delete();
        $this->resolvedRestrictions = [];
    }

    /**
     * ALS Management: Get all restrictions for a subject.
     */
    public function getAttributeRestrictions(\Illuminate\Database\Eloquent\Model $subject): array
    {
        return AttributeRestriction::where('subject_type', get_class($subject))
            ->where('subject_id', $subject->getKey())
            ->get()
            ->toArray();
    }
}
