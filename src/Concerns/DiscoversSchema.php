<?php

namespace Nisalatp\DynamicReportGenerator\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Nisalatp\DynamicReportGenerator\Types\ModelInfo;
use Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerException;
use Nisalatp\DynamicReportGenerator\Models\RestrictedModel;
use Symfony\Component\Finder\Finder;

/**
 * Schema Discovery — Model discovery, attribute listing, and model info resolution.
 *
 * Handles the engine's broker role: inspecting host application models,
 * merging physical columns with Virtual Attributes, and exposing a unified
 * schema API to any frontend framework or AI agent.
 */
trait DiscoversSchema
{
    /**
     * Lazy-load the list of allowed models on first API call.
     *
     * Combines auto-discovery (or explicit whitelist) with restriction
     * filtering and internal model exclusion to build the final set.
     */
    private function ensureModelsLoaded(): void
    {
        if ($this->allowedModels !== null) {
            return;
        }

        $this->allowedModels = [];
        $allModels = $this->getAllApplicationModels();
        $restricted = $this->getRestrictedModels();

        // Auto-exclude the package's own infrastructure models unless
        // the host application explicitly opts in via config.
        $excludeInternal = !config('dynamicreportgenerator.include_package_models', false);

        foreach ($allModels as $modelClass) {
            if (in_array($modelClass, $restricted)) {
                continue;
            }
            if ($excludeInternal && in_array($modelClass, self::INTERNAL_MODELS)) {
                continue;
            }
            $this->allowedModels[$modelClass] = $this->getModelInfo($modelClass);
        }
    }

    /**
     * Guard: throws ReportMakerException if the model is not in the allowed set.
     */
    private function ensureModelAllowed(string $modelClass): void
    {
        if (!isset($this->allowedModels[$modelClass])) {
            throw ReportMakerException::modelNotAllowed($modelClass);
        }
    }

    /**
     * Schema Discovery: Get all available reportable models (All models MINUS restricted models).
     *
     * @return array Array of allowed model class names.
     */
    public function getAvailableModels(): array
    {
        $this->ensureModelsLoaded();
        return array_keys($this->allowedModels);
    }

    /**
     * Schema Discovery: Discover and return ALL Eloquent models in the application.
     *
     * If the 'reportable_models' config key contains a non-empty whitelist, only
     * those models are returned. Otherwise, falls back to auto-discovery by
     * scanning the application's model directories via Symfony Finder.
     *
     * @return array Array of all model class names.
     */
    public function getAllApplicationModels(): array
    {
        if ($this->allApplicationModels !== null) {
            return $this->allApplicationModels;
        }

        // If the host explicitly whitelisted models in config, use that list
        // instead of auto-discovery. This gives full control over which
        // models are visible to the reporting engine.
        $configModels = config('dynamicreportgenerator.reportable_models', []);
        
        if (!empty($configModels)) {
            $this->allApplicationModels = array_values(array_filter($configModels, function ($class) {
                // Ensure the whitelisted class actually exists and is a valid Eloquent model
                return class_exists($class) && is_subclass_of($class, Model::class);
            }));
            
            return $this->allApplicationModels;
        }

        // Fallback: auto-discover all Eloquent models in the application.
        $models = [];
        $modelPaths = [app_path(), app_path('Models')];

        $finder = new Finder();
        $finder->files()->name('*.php')->in(array_filter($modelPaths, 'is_dir'));

        foreach ($finder as $file) {
            $path = $file->getRealPath();
            // A simple heuristic to find namespace and class name without regexing everything,
            // or simply use token_get_all. We'll use a reliable token extraction:
            $class = $this->extractClassFromFile($path);
            if ($class && class_exists($class) && is_subclass_of($class, Model::class)) {
                // Ensure it's not abstract or interface
                $reflection = new ReflectionClass($class);
                if (!$reflection->isAbstract() && !$reflection->isInterface()) {
                    $models[] = $class;
                }
            }
        }

        $this->allApplicationModels = array_unique($models);
        return $this->allApplicationModels;
    }

    /**
     * Schema Discovery: Get all attributes (physical and virtual) for a model, excluding blocked ones.
     *
     * @param string $modelClass
     * @return array Array of attribute names.
     */
    public function getModelAttributes(string $modelClass): array
    {
        $this->ensureModelsLoaded();
        $this->ensureModelAllowed($modelClass);

        $table = $this->allowedModels[$modelClass]->table;
        $physicalCols = Schema::getColumnListing($table);

        $virtualCols = [];
        if ($this->vaRegistry) {
            $virtualAttrs = $this->vaRegistry->getForModel($modelClass);
            $virtualCols = array_map(fn($va) => 'va:' . $va->name, $virtualAttrs);
        }

        $allCols = array_merge($physicalCols, $virtualCols);

        // Exclude blocked attributes from schema discovery so they don't even show up in UIs
        // We load restrictions for the current active user here.
        $this->resolveAttributeRestrictions(null);

        return array_values(array_filter($allCols, function ($col) use ($modelClass) {
            return $this->getRestrictionType($modelClass, $col, str_starts_with($col, 'va:')) !== 'blocked';
        }));
    }

    /**
     * Schema Discovery: Get all discoverable relationships for a model.
     *
     * @param string $modelClass
     * @return array Array of ModelLink objects detailing the relationships.
     */
    public function getModelRelationships(string $modelClass): array
    {
        $this->ensureModelsLoaded();
        $this->ensureModelAllowed($modelClass);

        $links = $this->discoverLinks();
        return $links[$modelClass] ?? [];
    }

    /**
     * Get all models connected to the given model via both forward and reverse relations.
     *
     * This is the bidirectional view of the relationship graph for a specific model,
     * merging explicitly declared Eloquent relationships with synthesized reverse edges.
     * Useful for UI components that need to show all reachable models from a starting point.
     *
     * @param string $modelClass The fully qualified model class name.
     * @return array<string, ModelLink> Map of connected model class => ModelLink.
     */
    public function getConnectedModels(string $modelClass): array
    {
        return $this->getModelRelationships($modelClass);
    }

    /**
     * Get the configured maximum filter nesting depth.
     *
     * Frontends should call this to limit how many levels of AND/OR group
     * nesting the UI permits. The same value is enforced server-side in
     * generate() to reject over-nested filter trees.
     *
     * @return int The maximum allowed nesting depth for filter groups.
     */
    public function getMaxFilterDepth(): int
    {
        return (int) config('dynamicreportgenerator.ui.max_filter_depth', 3);
    }

    /**
     * Resolve a ModelInfo DTO for a given model class.
     */
    private function getModelInfo(string $modelClass): ModelInfo
    {
        /** @var Model $instance */
        $instance = new $modelClass();
        return new ModelInfo(
            modelClass: $modelClass,
            table: $instance->getTable(),
            primaryKey: $instance->getKeyName(),
            casts: $instance->getCasts()
        );
    }

    /**
     * Token-based PHP class extractor for auto-discovery.
     */
    private function extractClassFromFile(string $path): ?string
    {
        $contents = file_get_contents($path);
        $namespace = '';
        $class = '';

        $tokens = token_get_all($contents);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            
            // 1. Find the Namespace definition
            if ($tokens[$i][0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j] === ';') {
                        break;
                    }
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED])) {
                        $namespace .= $tokens[$j][1];
                    }
                }
            }

            // 2. Find the Class declaration
            if ($tokens[$i][0] === T_CLASS) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j] === '{') {
                        break;
                    }
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $class = $tokens[$j][1];
                        break;
                    }
                }
                break; // Stop parsing after the first class is found
            }
        }

        if ($class) {
            return $namespace ? $namespace . '\\' . $class : $class;
        }

        return null;
    }
}
