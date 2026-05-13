# Dynamic Report Generator - UML Documentation & Code Mapping Guide

This document serves as a comprehensive academic guide to the UML diagrams located in the `diagrams/` directory. For your capstone defense, this guide maps each architectural diagram directly to its physical implementation in the codebase, providing concrete code samples to prove the theoretical flows.

---

## Part 1: Core Package Architecture (Backend Engine)

### `1_architecture.puml` (High-Level Architecture)
**Implementation Files**: `src/Providers/DynamicReportGeneratorServiceProvider.php`
**Explanation**: This diagram establishes the decoupled boundary between the Host Application (e.g., the Demo App) and the Package Engine. The engine is instantiated as a singleton so that the Virtual Attribute Registry caches queries once per request lifecycle.

**Code Evidence**:
```php
// DynamicReportGeneratorServiceProvider.php
public function register()
{
    $this->app->singleton(VirtualAttributeRegistry::class, function ($app) {
        return VirtualAttributeRegistry::getInstance();
    });

    $this->app->singleton('dynamic-report', function ($app) {
        $models = config('dynamicreportgenerator.reportable_models', []);
        return new ReportMaker($models, $app->make(VirtualAttributeRegistry::class));
    });
}
```

### `3_class_diagram.puml` (OOP Class Structure)
**Implementation Files**: `src/Types/ReportRequest.php`, `src/Types/FilterNode.php`
**Explanation**: Maps the strict Object-Oriented design of the Abstract Syntax Tree (AST). Instead of passing loose arrays, the engine forces the UI to map to strict DTOs.

**Code Evidence**:
```php
// ReportRequest.php (AST Root)
class ReportRequest {
    public function __construct(
        public readonly string $baseModel,
        public readonly array $targetModels = [],
        public readonly array $selectedAttributes = [],
        public readonly ?FilterNode $innerFilters = null,
        public readonly array $groupBys = [],
        public readonly array $aggregates = [],
        public readonly ?FilterNode $outerFilters = null
    ) {}
}
```

### `4.1_sequence_execution.puml` & `6_activity_diagram.puml` (Query Generation Flow)
**Implementation Files**: `src/ReportMaker.php` (`generate()` method)
**Explanation**: The activity diagram dictates the exact algorithm executed by the `generate()` function. It resolves Virtual Attribute dependencies first, maps Eloquent joins via BFS (Breadth-First Search), and then applies the AST to the Laravel Query Builder.

**Code Evidence**:
```php
// ReportMaker.php
public function generate(ReportRequest $whatUserWants): Builder
{
    $targetModels = $whatUserWants->targetModels;
    // Step 1: Extract VA dependencies
    $this->extractVirtualAttributeDependencies($whatUserWants, $targetModels);
    
    // Step 2: Bidirectional Link Discovery + BFS Join Planning
    // discoverLinks() returns a cached bidirectional graph:
    //   Phase 1: getForwardRelations() — reflection-based forward edges
    //   Phase 2: getReverseRelations() — synthesized inverse edges
    $links = $this->discoverLinks();
    $joinPlan = $this->planJoins($whatUserWants->baseModel, $targetModels, $links);

    // Step 3: Build Base Query
    $innerQuery = $this->buildInnerQuery(
        $whatUserWants->baseModel, $joinPlan, $whatUserWants->selectedAttributes, $whatUserWants->innerFilters
    );

    // Step 4: Wrap Subquery (If Aggregates Exist)
    if (!empty($whatUserWants->groupBys) || !empty($whatUserWants->aggregates)) {
        return $this->buildOuterQuery(..., $innerQuery, ...);
    }
    return $innerQuery;
}
```

### `4.6_sequence_virtual_attributes.puml` (VA Resolution)
**Implementation Files**: `src/ReportMaker.php` (`applyFilters()` and `buildInnerQuery()`)
**Explanation**: When the AST compiler encounters a `va:` prefix or `isVirtual = true`, it queries the `VirtualAttributeRegistry` to fetch the raw SQL subquery and injects it securely into the Builder using `DB::raw()`.

**Code Evidence**:
```php
// ReportMaker.php (Inside applyFilters)
$isVirtual = $node->attribute->isVirtual || str_starts_with($node->attribute->column, 'va:');
if ($isVirtual && $this->vaRegistry && $base) {
    $name = str_starts_with($node->attribute->column, 'va:') ? substr($node->attribute->column, 3) : $node->attribute->column;
    $va = $this->vaRegistry->findByName($base, $name);
    if ($va) {
        $col = DB::raw($va->sql_fragment); // Injects the raw subquery (e.g. SELECT COUNT(...))
    }
}
```

### `05_schema_discovery_flow.puml` (Schema Discovery Broker)
**Implementation Files**: `src/ReportMaker.php` (`getModelAttributes()`)
**Explanation**: Maps how the engine acts as an intermediary broker to shield external frontends from Laravel's internal reflection API, merging physical schema columns with Virtual Attributes dynamically.

**Code Evidence**:
```php
// ReportMaker.php
public function getModelAttributes(string $modelClass): array
{
    $table = $this->allowedModels[$modelClass]->table;
    $physicalCols = Schema::getColumnListing($table);
    $virtualAttrs = $this->vaRegistry->getForModel($modelClass);
    return array_merge($physicalCols, $virtualAttrs);
}

// NEW: Get all connected models (both forward-declared and reverse-synthesized)
public function getConnectedModels(string $modelClass): array
{
    $this->ensureModelAllowed($modelClass);
    $links = $this->discoverLinks(); // Uses cached bidirectional graph
    return $links[$modelClass] ?? [];
}
```

---

## Part 2: Demo Environment Integration & UI-Agnostic Services

### `01_report_generation_flow.puml`
**Implementation Files**: `src/Types/ReportBuilderRequest.php`, `src/Services/GovernanceManager.php`
**Explanation**: Shows how the host application controller uses the `ReportBuilderRequest` factory to transform the flat JSON sent by the frontend into the strict AST. It also explicitly shows the `GovernanceManager` intercepting the request to apply Attribute Level Security (ALS) rules before execution.

**Code Evidence**:
```php
// ReportBuilderRequest.php
public static function createFromPayload(array $payload): self
{
    $selectedAttributes = collect($payload['selectedAttributes'] ?? [])->map(function ($attr) {
        return new Attribute($attr['model'], $attr['column'], $attr['type']);
    })->toArray();

    return new self(
        baseModel: $payload['baseModel'],
        targetModels: $payload['targetModels'] ?? [],
        selectedAttributes: $selectedAttributes,
        // ...
    );
}
```

### `02_virtual_attribute_builder_flow.puml`
**Implementation Files**: `src/Services/VirtualAttributeCompiler.php`, `src/Builders/VirtualAttributeBuilder.php`
**Explanation**: Details how the frontend visual "No-Code" dropdowns are sent to the `/va-builder/compile` endpoint, where the `VirtualAttributeCompiler` securely generates the SQL fragment using Laravel's Reflection API, before passing it to the Fluent Builder pattern for registration.

**Code Evidence**:
```php
// VirtualAttributeCompiler.php
public static function compileVisualPayload(array $payload): string
{
    $baseModel = $payload['baseModel'];
    $targetModel = $payload['ast'][0]['model'];
    // Reflection logic safely maps table names and relationships...
    return "(SELECT {$agg}({$col}) FROM {$targetTable} WHERE ...)";
}
```

### `03_save_and_load_report_flow.puml`
**Implementation Files**: `src/ReportMaker.php` (`saveReport()`)
**Explanation**: Demonstrates how the backend strictly validates the AST using the Factory before serializing it into a JSON payload and saving it into the `dynamic_saved_reports` table via Eloquent.

### `04_execute_saved_report_flow.puml`
**Implementation Files**: `src/Types/ReportBuilderRequest.php` (`createFromSavedReport()`)
**Explanation**: Maps how a previously saved report bypasses UI serialization completely. The database state is hydrated directly into the AST DTO for completely secure, server-side execution.

### `05_attribute_level_security_setup_flow.puml`
**Implementation Files**: `src/Services/GovernanceManager.php`
**Explanation**: Visualizes the `GovernanceManager` service abstracting the `dynamic_attribute_restrictions` table. It shows the flow for converting an Admin's UI selection into `masked` or `blocked` rules.

### `06_report_assignment_flow.puml`
**Implementation Files**: `src/Models/SavedReport.php`
**Explanation**: Demonstrates the use of the `dynamic_report_user` pivot table and Eloquent's `sync()` method to achieve stateless, array-based access control to Saved Reports.

---

## Part 3: Academic Highlights & System Architecture Theory

For an academic capstone defense, evaluators look for the application of Computer Science theory, algorithms, and recognized design patterns. Use the following concepts to supplement your UML diagrams:

### 1. Applied Design Patterns (Gang of Four & Enterprise)
The architecture rigorously employs several proven software design patterns, which are clearly visible in the `3_class_diagram.puml` and `7_package_diagram.puml`:
- **Composite Pattern**: The Abstract Syntax Tree (AST) for filters (`FilterNode` interface) utilizes the Composite pattern. A `FilterGroup` contains an array of `FilterNode` children (which can be either `FilterLeaf` or another `FilterGroup`), allowing the engine to traverse infinitely nested `AND/OR` SQL conditions recursively.
- **Builder / Fluent Interface Pattern**: Implemented in the `VirtualAttributeBuilder` to allow step-by-step chaining (e.g., `create()->forBaseModel()->withSqlFragment()->register()`), isolating the complexity of model creation from the controller.
- **Singleton Pattern**: The `VirtualAttributeRegistry` is instantiated as a Singleton via the Laravel Service Provider. This ensures that the registry loads the raw SQL strings from the database only **once** per request lifecycle, eliminating N+1 query problems during report generation.
- **Facade Pattern**: The `DynamicReport` Facade abstracts the complex instantiation of the `ReportMaker` engine, providing a clean, static interface for the host application to use (e.g., `DynamicReport::generate()`).
- **Broker Pattern**: The `ReportMaker` engine acts as a Schema Discovery Broker. It intercepts frontend requests for models/attributes and handles the complex PHP Reflection and database schema lookups internally, returning a clean array. This isolates the frontend from backend structural complexities.
- **Data Transfer Object (DTO)**: The entire `ReportRequest` acts as a strongly-typed DTO payload, separating the unpredictable shape of the HTTP request from the strict internal logic of the engine.

### 2. Algorithm Analysis: Breadth-First Search (BFS) with Bidirectional Traversal
When presenting `6_activity_diagram.puml`, highlight the **BFS Algorithm** used for the Auto-Join capability.
- **The Problem**: When a user selects columns from `Users` and `Orders`, the engine needs to know *how* to join them.
- **The Solution**: The engine uses PHP Reflection to map all Eloquent relationships into a Graph network. It performs **two-phase bidirectional discovery**: first scanning forward-declared relationships via reflection (`getForwardRelations`), then synthesizing missing reverse edges (`getReverseRelations`) by inverting relationship types (BelongsTo↔HasMany, HasOne→BelongsTo, BelongsToMany with swapped keys). It then runs a Breadth-First Search (`findShortestPath` method in `ReportMaker`) across this bidirectional graph to find the shortest relational path, automatically generating the `JoinPlan` array. Each `JoinStep` carries a `direction` field (`'forward'` or `'reverse'`) indicating how the edge was discovered. This is a classic application of Graph Theory enhanced with reverse inference.

### 3. Database Architecture & Performance Optimization
When presenting `2_erd.puml`, mention how the design optimizes for large datasets:
- **Subquery Pushdown**: The Virtual Attribute system avoids processing data in PHP RAM. Instead of hydrating Eloquent models and running `->count()`, the system transpiles the logic into raw SQL Subqueries and pushes them down to the Database Engine, allowing MySQL/SQLite to execute aggregations at hardware speed.
- **JSON Payload Storage**: The `dynamic_saved_reports` table leverages modern RDBMS JSON column capabilities to store the serialized AST, allowing the configuration to be completely schema-less while remaining robust.
