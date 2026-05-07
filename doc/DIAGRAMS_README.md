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
    
    // Step 2: BFS Join Planning
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

---

## Part 2: Demo Environment Integration (Frontend-to-Backend)

### `10.1_demo_flow_generate_report.puml`
**Implementation Files**: `demo-app/app/Http/Controllers/ReportBuilderController.php`
**Explanation**: Shows how the host application controller acts as a translation layer between the flat JSON sent by the AlpineJS UI and the strict AST expected by the Engine.

**Code Evidence**:
```php
// ReportBuilderController.php
private function buildReportRequest(array $payload): ReportRequest
{
    // Maps the flat array from the UI into strict AST DTOs
    $selectedAttributes = collect($payload['selectedAttributes'] ?? [])->map(function ($attr) {
        return new Attribute($attr['model'], $attr['column'], $attr['type']);
    })->toArray();

    return new ReportRequest(
        baseModel: $payload['baseModel'],
        targetModels: $payload['targetModels'] ?? [],
        selectedAttributes: $selectedAttributes,
        ...
    );
}
```

### `10.2_demo_flow_save_report.puml`
**Implementation Files**: `src/ReportMaker.php` (`saveReport()`)
**Explanation**: Demonstrates how the backend serializes the AST into a JSON payload and saves it into the `dynamic_saved_reports` table via Eloquent.

**Code Evidence**:
```php
// ReportMaker.php
public function saveReport(string $name, ReportRequest $request, ?int $userId = null, string $description = ''): SavedReport
{
    return SavedReport::create([
        'name' => $name,
        'description' => $description,
        // json_decode forces Eloquent's array cast to handle encoding properly
        'payload' => json_decode($request->toJson(), true), 
        'user_id' => $userId,
    ]);
}
```

### `10.3_demo_flow_load_report.puml`
**Implementation Files**: `demo-app/resources/views/builder.blade.php` (`loadReportToEditor()`)
**Explanation**: **Highly critical for the defense.** It maps the reverse-translation process where the strict backend AST is fetched from the database and flattened back into the visual UI state so AlpineJS can re-render the visual blocks.

**Code Evidence**:
```javascript
// builder.blade.php
async loadReportToEditor(id) {
    const response = await fetch(`/builder/saved/${id}/load`);
    const data = await response.json();
    
    let ast = data.payload;
    if (typeof ast === 'string') ast = JSON.parse(ast); // Graceful fallback
    
    // Translates strictly-typed Backend AST back to flat AlpineJS state
    const selAttrs = (ast.selectedAttributes || []).map(a => ({
        model: a.modelClass,
        column: a.column,
        type: a.type,
        alias: a.alias || null
    }));

    this.payload.selectedAttributes = selAttrs; // Triggers UI reactivity
}
```

### `10.5_demo_flow_register_visual_va.puml` & `10.6_demo_flow_register_advanced_va.puml`
**Implementation Files**: `demo-app/resources/views/va-builder.blade.php`, `src/Builders/VirtualAttributeBuilder.php`
**Explanation**: Details how AlpineJS transpiles visual "No-Code" dropdowns into a raw SQL fragment on the frontend, and passes it to the Fluent Builder pattern on the backend to register the Virtual Attribute.

**Code Evidence**:
```javascript
// va-builder.blade.php (Frontend No-Code Transpilation)
compileVisualSql() {
    // Converts UI state into raw SQL representation
    this.payload.sqlFragment = `(SELECT ${this.visual.operation}(${this.visual.targetColumn}) FROM ${target.table} WHERE ${target.table}.${this.visual.targetModelKey} = t0.${this.visual.baseModelKey})`;
}
```

```php
// VirtualAttributeBuilderController.php (Backend Registration via Fluent API)
$builder = VirtualAttributeBuilder::create($payload['name'])
    ->forBaseModel($payload['baseModel'])
    ->withSqlFragment($payload['sqlFragment'])
    ->dependsOn($payload['dependencies'])
    ->register();
```

---

## Part 3: Academic Highlights & System Architecture Theory

For an academic capstone defense, evaluators look for the application of Computer Science theory, algorithms, and recognized design patterns. Use the following concepts to supplement your UML diagrams:

### 1. Applied Design Patterns (Gang of Four & Enterprise)
The architecture rigorously employs several proven software design patterns, which are clearly visible in the `3_class_diagram.puml` and `7_package_diagram.puml`:
- **Composite Pattern**: The Abstract Syntax Tree (AST) for filters (`FilterNode` interface) utilizes the Composite pattern. A `FilterGroup` contains an array of `FilterNode` children (which can be either `FilterLeaf` or another `FilterGroup`), allowing the engine to traverse infinitely nested `AND/OR` SQL conditions recursively.
- **Builder / Fluent Interface Pattern**: Implemented in the `VirtualAttributeBuilder` to allow step-by-step chaining (e.g., `create()->forBaseModel()->withSqlFragment()->register()`), isolating the complexity of model creation from the controller.
- **Singleton Pattern**: The `VirtualAttributeRegistry` is instantiated as a Singleton via the Laravel Service Provider. This ensures that the registry loads the raw SQL strings from the database only **once** per request lifecycle, eliminating N+1 query problems during report generation.
- **Facade Pattern**: The `DynamicReport` Facade abstracts the complex instantiation of the `ReportMaker` engine, providing a clean, static interface for the host application to use (e.g., `DynamicReport::generate()`).
- **Data Transfer Object (DTO)**: The entire `ReportRequest` acts as a strongly-typed DTO payload, separating the unpredictable shape of the HTTP request from the strict internal logic of the engine.

### 2. Algorithm Analysis: Breadth-First Search (BFS)
When presenting `6_activity_diagram.puml`, highlight the **BFS Algorithm** used for the Auto-Join capability.
- **The Problem**: When a user selects columns from `Users` and `Orders`, the engine needs to know *how* to join them.
- **The Solution**: The engine uses PHP Reflection to map all Eloquent relationships into a Graph network. It then runs a Breadth-First Search (`findShortestPath` method in `ReportMaker`) to find the shortest relational path between the base table and the target table, automatically generating the `JoinPlan` array. This is a classic application of Graph Theory.

### 3. Database Architecture & Performance Optimization
When presenting `2_erd.puml`, mention how the design optimizes for large datasets:
- **Subquery Pushdown**: The Virtual Attribute system avoids processing data in PHP RAM. Instead of hydrating Eloquent models and running `->count()`, the system transpiles the logic into raw SQL Subqueries and pushes them down to the Database Engine, allowing MySQL/SQLite to execute aggregations at hardware speed.
- **JSON Payload Storage**: The `dynamic_saved_reports` table leverages modern RDBMS JSON column capabilities to store the serialized AST, allowing the configuration to be completely schema-less while remaining robust.
