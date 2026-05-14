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
public function register(): void
{
    $this->mergeConfigFrom(
        __DIR__.'/../../config/dynamicreportgenerator.php', 'dynamicreportgenerator'
    );

    $this->app->singleton(ReportMaker::class, function ($app) {
        return new ReportMaker(
            new VirtualAttributeRegistry()
        );
    });
}
```

### `3_class_diagram.puml` (OOP Class Structure)
**Implementation Files**: `src/Types/ReportRequest.php`, `src/Types/FilterNode.php`, `src/Types/Attribute.php`
**Explanation**: Maps the strict Object-Oriented design of the Abstract Syntax Tree (AST). Instead of passing loose arrays, the engine forces the UI to map to strict DTOs. The `Attribute` DTO now includes an optional `alias` field for column renaming.

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
        public readonly ?FilterNode $outerFilters = null,
        public readonly array $sorts = []
    ) {}
}
```

### `4.1_sequence_execution.puml` & `6_activity_diagram.puml` (Query Generation Flow)
**Implementation Files**: `src/ReportMaker.php` (`generate()` method)
**Explanation**: The activity diagram dictates the exact algorithm executed by the `generate()` function. It validates security constraints first (model access, ALS, filter depth), resolves Virtual Attribute dependencies, maps Eloquent joins via BFS (Breadth-First Search), and then applies the AST to the Laravel Query Builder. A configurable `max_rows` limit is enforced as a final safety boundary.

**Code Evidence**:
```php
// ReportMaker.php
public function generate(ReportRequest $whatUserWants, ?array $subjects = null): Builder
{
    $this->ensureModelsLoaded();
    $this->ensureModelAllowed($whatUserWants->baseModel);
    $this->resolveAttributeRestrictions($subjects);
    $this->validateSecurity($whatUserWants);
    $this->validateFilterDepth($whatUserWants->innerFilters, 'WHERE');
    $this->validateFilterDepth($whatUserWants->outerFilters, 'HAVING');

    $targetModels = $whatUserWants->targetModels;
    // Step 1: Extract VA dependencies
    $this->extractVirtualAttributeDependencies($whatUserWants, $targetModels);
    
    // Step 2: Bidirectional Link Discovery + BFS Join Planning
    $links = $this->discoverLinks();
    $joinPlan = $this->planJoins($whatUserWants->baseModel, $targetModels, $links);

    // Step 3: Build Base Query (applies ALS masking/blocking in SELECT)
    $innerQuery = $this->buildInnerQuery(
        $whatUserWants->baseModel, $joinPlan,
        $whatUserWants->selectedAttributes, $whatUserWants->innerFilters
    );

    // Step 4: Wrap Subquery (If Aggregates Exist)
    if (!empty($whatUserWants->groupBys) || !empty($whatUserWants->aggregates)) {
        $query = $this->buildOuterQuery(..., $innerQuery, ...);
    } else {
        $query = $innerQuery;
    }

    // Step 5: Enforce max_rows safety limit
    $maxRows = config('dynamicreportgenerator.limits.max_rows');
    if ($maxRows) {
        $query->limit($maxRows);
    }

    return $query;
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
    $va = $this->vaRegistry->findByName($node->attribute->modelClass, $name);
    if ($va) {
        $aliasPrefix = $aliases[$node->attribute->modelClass] ?? 't0';
        $fragment = str_replace('{THIS}', $aliasPrefix, $va->sql_fragment);
        $col = DB::raw($fragment); // Injects the raw subquery with correct table alias
    }
}
```

### `05_schema_discovery_flow.puml` (Schema Discovery Broker)
**Implementation Files**: `src/ReportMaker.php` (`getModelAttributes()`, `getConnectedModels()`, `getMaxFilterDepth()`)
**Explanation**: Maps how the engine acts as an intermediary broker to shield external frontends from Laravel's internal reflection API, merging physical schema columns with Virtual Attributes dynamically. The broker now also exposes `getMaxFilterDepth()` so frontends can enforce the same nesting limit.

**Code Evidence**:
```php
// ReportMaker.php
public function getModelAttributes(string $modelClass): array
{
    $this->ensureModelsLoaded();
    $this->ensureModelAllowed($modelClass);

    $table = $this->allowedModels[$modelClass]->table;
    $physicalCols = Schema::getColumnListing($table);

    $virtualCols = [];
    if ($this->vaRegistry) {
        $virtualAttrs = $this->vaRegistry->getForModel($modelClass);
        $virtualCols = $virtualAttrs->map(fn($va) => 'va:' . $va->name)->toArray();
    }

    $allCols = array_merge($physicalCols, $virtualCols);

    // Exclude blocked attributes from schema discovery
    $this->resolveAttributeRestrictions(null);
    return array_values(array_filter($allCols, function ($col) use ($modelClass) {
        return $this->getRestrictionType($modelClass, $col, str_starts_with($col, 'va:')) !== 'blocked';
    }));
}

// Get all connected models — delegates to getModelRelationships()
public function getConnectedModels(string $modelClass): array
{
    return $this->getModelRelationships($modelClass);
}

// Expose configured filter nesting depth to frontends
public function getMaxFilterDepth(): int
{
    return (int) config('dynamicreportgenerator.ui.max_filter_depth', 3);
}
```

---

## Part 2: Demo Environment Integration & UI-Agnostic Services

### `01_report_generation_flow.puml`
**Implementation Files**: `src/Http/Requests/ReportBuilderRequest.php`, `src/Services/GovernanceManager.php`
**Explanation**: Shows how the host application controller uses the `ReportBuilderRequest` factory to transform the flat JSON sent by the frontend into the strict AST. It also explicitly shows the `GovernanceManager` intercepting the request to apply Attribute Level Security (ALS) rules before execution.

**Code Evidence**:
```php
// ReportBuilderRequest.php
public static function fromPayload(array $payload): ReportRequest
{
    $selectedAttributes = collect($payload['selectedAttributes'] ?? [])->map(function ($attr) {
        return new Attribute(
            $attr['model'], $attr['column'], $attr['type'] ?? 'string',
            isVirtual: $attr['isVirtual'] ?? false,
            alias: $attr['alias'] ?? null
        );
    })->toArray();

    return new ReportRequest(
        baseModel: $payload['baseModel'],
        targetModels: $payload['targetModels'] ?? [],
        selectedAttributes: $selectedAttributes,
        innerFilters: self::parseFilterNode($payload['innerFilters'] ?? null, false),
        // ...
    );
}
```

### `02_virtual_attribute_builder_flow.puml`
**Implementation Files**: `src/Services/VirtualAttributeCompiler.php`, `src/Builders/VirtualAttributeBuilder.php`
**Explanation**: Details how the frontend visual "No-Code" dropdowns are sent to the `/va-builder/compile` endpoint, where the `VirtualAttributeCompiler` securely generates the SQL fragment using the engine's `buildScalarSubquery()` method, before passing it to the Fluent Builder pattern for registration.

**Code Evidence**:
```php
// VirtualAttributeCompiler.php
public static function compileVisualPayload(array $payload): string
{
    $request = VirtualAttributeRequest::fromArray($payload);
    $reportMaker = app(ReportMaker::class);
    return $reportMaker->buildScalarSubquery($request);
}

// VirtualAttributeBuilder.php (Fluent Builder Pattern)
VirtualAttributeBuilder::create('total_order_value')
    ->forBaseModel(User::class)
    ->withSqlFragment($compiledSql)
    ->dependsOn([Order::class])
    ->register();
```

### `03_save_and_load_report_flow.puml`
**Implementation Files**: `src/ReportMaker.php` (`saveReport()`, `loadToEditor()`)
**Explanation**: Demonstrates how the backend strictly validates the AST using the Factory before serializing it into a JSON payload and saving it into the `dynamic_saved_reports` table via Eloquent. When loading, `loadToEditor()` uses `ReportSerializer::fromJson()` to reconstruct the full AST — including preserved column aliases — for frontend rehydration.

### `04_execute_saved_report_flow.puml`
**Implementation Files**: `src/ReportMaker.php` (`loadAndGenerate()`)
**Explanation**: Maps how a previously saved report bypasses UI serialization completely. The database state is hydrated directly into the AST DTO via `ReportSerializer::fromJson()` for completely secure, server-side execution. The execution is automatically logged in `dynamic_report_logs`.

### `05_attribute_level_security_setup_flow.puml`
**Implementation Files**: `src/Services/GovernanceManager.php`
**Explanation**: Visualizes the `GovernanceManager` service abstracting the `dynamic_attribute_restrictions` table. It shows the flow for converting an Admin's UI selection into `masked` or `blocked` rules.

### `06_report_assignment_flow.puml`
**Implementation Files**: `src/Models/SavedReport.php`
**Explanation**: Demonstrates the use of the `dynamic_report_user` pivot table and Eloquent's `attach()`/`detach()` methods to achieve stateless, array-based access control to Saved Reports. Both assign and unassign operations are logged in `dynamic_report_logs`.

---

## Part 3: Academic Highlights & System Architecture Theory

For an academic capstone defense, evaluators look for the application of Computer Science theory, algorithms, and recognized design patterns. Use the following concepts to supplement your UML diagrams:

### 1. Applied Design Patterns (Gang of Four & Enterprise)
The architecture rigorously employs several proven software design patterns, which are clearly visible in the `3_class_diagram.puml` and `7_package_diagram.puml`:
- **Composite Pattern**: The Abstract Syntax Tree (AST) for filters (`FilterNode` interface) utilizes the Composite pattern. A `FilterGroup` contains an array of `FilterNode` children (which can be either `FilterLeaf` or another `FilterGroup`), allowing the engine to traverse infinitely nested `AND/OR` SQL conditions recursively.
- **Builder / Fluent Interface Pattern**: Implemented in the `ReportBuilder` and `FilterBuilder` for programmatic report construction (e.g., `ReportBuilder::forModel(User::class)->select(...)->filter(...)->build()`), and in `VirtualAttributeBuilder` for step-by-step VA registration (e.g., `create()->forBaseModel()->withSqlFragment()->register()`). This isolates the complexity of DTO construction from the host controller.
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
- **Cursor-Based CSV Streaming**: Data exports use Laravel's `cursor()` with a Symfony `StreamedResponse`, maintaining O(1) PHP memory by piping rows directly to the HTTP connection without buffering.

### 4. Security & Safety Boundaries
When presenting the architecture, highlight the defense-in-depth approach:
- **DTO Whitelist Validation**: `Aggregate`, `FilterLeaf`, and `Sort` constructors all validate against immutable whitelists, preventing SQL injection at the DTO boundary.
- **Parameterized Bindings**: All dynamic values — including `HAVING ... IN (...)` — use PDO `?` placeholders instead of string interpolation.
- **Filter Depth Limits**: `validateFilterDepth()` recursively measures nesting depth and rejects requests exceeding the configured `max_filter_depth` (default: 3). The same limit is exposed to frontends via `getMaxFilterDepth()`.
- **Execution Row Limits**: A configurable `max_rows` (default: 5000) is applied via `->limit()` to all generated queries as a safety net.
- **Internal Model Auto-Exclusion**: Package infrastructure tables are automatically hidden from the reportable model list via the `INTERNAL_MODELS` constant, configurable via `include_package_models`.
