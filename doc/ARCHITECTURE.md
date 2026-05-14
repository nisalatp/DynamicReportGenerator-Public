# System Architecture Explanation
## Dynamic Report Generator (Laravel Package)

This document provides a deep, theoretical dive into the underlying architecture of the Dynamic Report Generator. It is designed to explain the "Why" and "How" of the system's design patterns, algorithms, and architectural constraints.

---

## 1. High-Level Architectural Paradigm

The system is built using a **Decoupled Client-Server Service Layer** within an **MVC (Model-View-Controller)** framework.

### 1.1 The Decoupled Package Paradigm
Rather than building the reporting logic directly into the host application, this system is designed as an independent, installable **Laravel Package**. 
- **Why?**: This guarantees high cohesion and low coupling. The host application only interacts with the engine via strict public APIs (the `DynamicReport` Facade). The engine has no knowledge of the host application's UI, session state, or authentication logic.
- **Service Provider Pattern**: The package boots itself via the `DynamicReportGeneratorServiceProvider`, which registers configurations, database migrations, and binds the `ReportMaker` and `VirtualAttributeRegistry` classes as Singleton services into Laravel's IoC (Inversion of Control) container.

### 1.2 Facade Pattern
The `DynamicReport` Facade acts as the single point of entry for the host application. It hides the complexity of instantiating the `ReportMaker` engine and dependency injection, offering a simple, static API (e.g., `DynamicReport::generate()`, `DynamicReport::saveReport()`).

---

## 2. The Abstract Syntax Tree (AST) & DTO Boundary

One of the most critical design decisions in the engine is the rejection of loose data structures (like PHP arrays or raw JSON) in favor of an **Abstract Syntax Tree (AST)** composed of strictly typed **Data Transfer Objects (DTOs)**.

### 2.1 The `ReportRequest` Payload
When a host application requests a report, it must provide a `ReportRequest` object. This object acts as the "source code" for the engine's compiler.
- **Why?**: Loose arrays are prone to silent failures and malicious injections. By enforcing strict DTOs (`Attribute`, `FilterGroup`, `FilterLeaf`), the engine guarantees the structural integrity of the request *before* execution begins.

### 2.2 The Composite Pattern for Filtering
The SQL `WHERE` and `HAVING` clauses are modeled using the **Composite Pattern**.
- The `FilterNode` interface acts as the component.
- `FilterLeaf` represents an individual condition (e.g., `age > 18`).
- `FilterGroup` represents a logical block (`AND` / `OR`) containing an array of `FilterNode` children.
- **Why?**: This pattern enables the engine to recursively traverse infinitely nested sub-conditions gracefully without complex iterative loops.

---

## 3. Algorithm Analysis: Auto-Joining via Graph Theory

A hallmark feature of the Dynamic Report Generator is its ability to automatically join disparate database tables without the user explicitly defining the join clauses.

### 3.1 Mapping the Relational Graph (Bidirectional Discovery)
The engine utilizes **PHP Reflection** to inspect the host application's Eloquent Models dynamically at runtime. It analyzes method return types (e.g., `BelongsTo`, `HasMany`) to discover foreign keys, local keys, and table relationships. These relationships are mapped into an adjacency list representing a **Directed Graph**.

Critically, the engine performs **two-phase link discovery**:
1. **Forward Relation Discovery (`getForwardRelations`)**: Scans each allowed model's public methods via reflection to find explicitly declared Eloquent relationships. Each relationship becomes a directed forward edge (e.g., `Order --[BelongsTo]--> User`).
2. **Reverse Relation Synthesis (`getReverseRelations`)**: For every forward edge A→B, if the inverse edge B→A does not already exist (i.e., the developer did not declare both directions), the engine synthesizes the reverse edge by inverting the relationship type (`BelongsTo ↔ HasMany`, `HasOne → BelongsTo`, `BelongsToMany` with swapped pivot keys). This ensures the graph is fully **bidirectional**.

Each edge in the graph carries a `direction` field (`'forward'` or `'reverse'`) to indicate how it was discovered, which is propagated through to the `JoinPlan` for debugging and frontend visualization.

The resulting bidirectional graph is **cached in-memory** for the lifetime of the `ReportMaker` instance, avoiding redundant reflection overhead on repeated calls.

### 3.2 Breadth-First Search (BFS)
When a user selects columns from different tables (e.g., pulling "Department Name" on a "User" report), the engine must figure out the `JOIN` path.
- The engine runs a **Breadth-First Search (BFS)** algorithm across the **bidirectional** relational graph.
- BFS guarantees the shortest possible relational path between the Base Model and the Target Model.
- Because the graph now includes both forward-declared and reverse-synthesized edges, BFS can discover paths that traverse relationships in **either direction** — even when only one side of a relationship was explicitly declared on a model.
- **Why?**: Generating the shortest path minimizes the number of `LEFT JOIN` clauses required, massively optimizing the resulting SQL query performance and eliminating circular join errors. Bidirectional traversal is critical for scenarios like family-relationship models where stored relationships are one-directional (e.g., `father_id`, `mother_id`) but downward traversal (parent→child) must also be discoverable.

### 3.3 Cycle Protection
The BFS visited set tracks model class names — since each model class is unique in the graph, this prevents infinite loops through spouse-like bidirectional relationships, self-referencing models, or any circular relationship patterns without requiring path-signature-based tracking.

---

## 4. Virtual Attributes, UX Abstraction & Subquery Pushdown

The Virtual Attribute (VA) system solves the primary business driver of the project: "Data Democratization." It allows developers to attach calculated fields to models (e.g., "Total Spent" on a `User`), so non-technical users can consume them without needing database knowledge.

### 4.1 UX Abstraction Layer
From a User Experience (UX) perspective, a Virtual Attribute acts as a 2D abstraction of a 3D relational graph.
- **The Problem**: Expecting a floor worker to know that calculating "Total Overtime" requires joining the `users`, `timesheets`, and `pay_periods` tables is an impossible UX hurdle.
- **The Solution**: The VA Builder allows an engineer to pre-configure this complex 3D relational logic into a raw SQL fragment. The Dynamic Report UI then presents this to the end-user simply as a single "Virtual Attribute" checkbox natively attached to the `User` model. The user clicks it, completely unaware of the massive SQL join logic occurring behind the scenes.

### 4.2 Subquery Pushdown Optimization
A naive reporting engine would fetch all `User` records, load their related `Orders` into PHP memory, and use a `foreach` loop to sum the totals. This approach scales terribly and causes Out-Of-Memory (OOM) fatal errors on large datasets.
- **The Solution**: The engine employs **Subquery Pushdown**. Virtual Attributes are stored as raw SQL strings (e.g., `(SELECT SUM(amount) FROM orders WHERE user_id = users.id HAVING SUM(amount) > 100)`). The engine parses the AST (`innerFilters` and `outerFilters`) and injects these strings directly into the `SELECT` clause using `DB::raw()`. 
- **Why?**: This offloads the computational heavy lifting to the Relational Database Management System (RDBMS). MySQL, Postgres, and SQLite execute C-level aggregations incredibly fast, maintaining an O(1) memory footprint in the PHP application regardless of the dataset size.

### 4.3 The Singleton Registry Cache
Virtual Attributes are fetched from the database via the `VirtualAttributeRegistry`.
- **Why?**: The Registry is bound as a Singleton. It fetches all VA strings from the database *once* per HTTP request and caches them in memory. When the compiler processes 50 different selected columns, it references the in-memory cache, eliminating the N+1 database query problem.

---

## 5. Persistence & Schema-less Storage

The engine supports saving and loading report configurations via the `SavedReport` model.

### 5.1 JSON Column Flexibility
The AST payload is JSON encoded and stored in the `payload` column of the `dynamic_saved_reports` table.
- **Why?**: By utilizing modern RDBMS JSON column capabilities, the database schema is completely decoupled from the structure of the AST. If a new filter type or configuration option is added to the `ReportRequest` in a future version, the database schema does not require a migration. 

### 5.2 Decoupled UI State Mapping
The backend explicitly stores the strictly typed AST, *not* the visual state of the AlpineJS UI blocks. 
- **Why?**: This separates concerns. The backend does not care how the frontend renders the builder. When the frontend loads a saved report, it is responsible for flattening the complex AST tree back into its own visual state blocks. This guarantees that the engine's core logic will not break if the Host Application decides to switch its UI framework from Vue to React.

---

## 6. Schema Discovery Broker Pattern

A core tenet of the Dynamic Report Generator is maintaining strict boundaries between the engine and the host application (or third-party frontends like React, Vue, or an AI Agent).

### 6.1 The Problem with Manual Reflection
Initially, host applications were required to manually instantiate Eloquent models and utilize Laravel's `Schema` builder to discover table columns. 
- **The Issue**: This violated the decoupling principle. A Vue SPA or an MCP-integrated AI Agent has no access to Laravel's internal `Schema` builder, making it impossible for them to automatically construct dropdowns or understand the data graph.

### 6.2 The Engine as a Broker
To solve this, the `ReportMaker` engine acts as a **Schema Discovery Broker**. 
- It exposes six critical methods via the `DynamicReport` Facade: `getAvailableModels()`, `getAllApplicationModels()`, `getModelAttributes()`, `getModelRelationships()`, `getConnectedModels()`, and `getMaxFilterDepth()`.
- **Bidirectional Relationship Discovery**: The `getConnectedModels()` method returns both forward-declared Eloquent relationships and reverse-synthesized edges. Each relationship includes a `direction` field (`'forward'` or `'reverse'`), enabling the frontend to distinguish between explicitly declared relationships and engine-inferred reverse edges.
- **Intelligent Merging**: When an external application requests the attributes for the `Order` model, the engine queries the physical database schema *and* the `VirtualAttributeRegistry`. It merges these together, prefixing Virtual Attributes with `va:`, and returns a unified list.
- **Configuration Exposure**: The `getMaxFilterDepth()` method returns the configured maximum AND/OR nesting depth, allowing frontends to proactively limit the nesting depth in the visual filter builder before submission.
- **Why?**: This completely encapsulates the reflection logic. Any frontend application, regardless of language or framework, can simply query the engine to receive a perfect, 1D abstraction of the physical schema and the 3D relational graph. The bidirectional discovery ensures that the frontend always sees complete connectivity information, even when models only declare one side of a relationship.

## 7. Data Governance & Attribute Level Security (ALS)

The engine provides a built-in Attribute-Level Security module to prevent unauthorized access to specific columns (or Virtual Attributes) directly at the SQL compilation level. This is decoupled from the host application's specific Role/User models via Polymorphic relations.

### 7.1 Security States
- **Masked (`***`)**: The attribute is allowed in backend query logic (`WHERE`, `GROUP BY`, `HAVING`) but is replaced with `***` in the `SELECT` output, preventing data leakage while allowing aggregate logic.
- **Blocked (`###`)**: The attribute is strictly prohibited. If it exists in the AST (`filters`, `groups`, `aggregates`, `sorts`), the compiler throws a `ReportMakerSecurityException`. If it is merely selected, its output is replaced with `###`. Blocked attributes are entirely hidden from the Schema Discovery APIs.

### 7.2 Subject Resolution
Host applications can implement the `Nisalatp\DynamicReportGenerator\Contracts\DynamicReportSubject` interface on their authentication models (e.g., `User`). The `getDynamicReportSubjects()` method allows the host to return an array of subjects (e.g., the User and their Roles) so the engine can aggregate all applicable restrictions for the current execution context.

---

## 8. Security & Memory Protection Abstractions

To ensure the engine is fully enterprise-ready, it implements strict boundaries to prevent SQL injection and Out-Of-Memory (OOM) fatal errors.

### 8.1 Strict DTO Validation (SQL Injection Prevention)
When processing aggregated calculations (e.g., `SUM`, `COUNT`), the `Aggregate` DTO acts as a rigid security perimeter.
- **The Threat**: If the `function` string was passed directly to `DB::raw()`, a malicious user could intercept the frontend JSON payload and inject a subquery like `SUM); DROP TABLE users; --`.
- **The Solution**: The `Aggregate` DTO contains an immutable `ALLOWED_FUNCTIONS` whitelist (`['SUM', 'AVG', 'COUNT', 'MIN', 'MAX']`). The DTO's constructor strictly validates the incoming string against this whitelist, completely neutralizing SQL injection vectors before the compilation phase even begins. The same pattern is applied to `FilterLeaf` (operator whitelist) and `Sort` (direction whitelist).
- **Parameterized Bindings**: All dynamic filter values — including complex `HAVING ... IN (...)` clauses — use PDO parameterized `?` placeholders instead of string interpolation, ensuring comprehensive SQL injection prevention across all filter paths.

### 8.2 Filter Nesting Depth Validation
Deeply nested AND/OR filter groups can produce complex SQL and represent a potential abuse vector.
- **The Solution**: The `validateFilterDepth()` method recursively measures the nesting depth of `FilterGroup` nodes in both `WHERE` (innerFilters) and `HAVING` (outerFilters) trees. If the depth exceeds the configured `max_filter_depth` limit (default: 3), a `ReportMakerException` is thrown with a descriptive error indicating the clause type and the configured limit.
- **Frontend Alignment**: The same limit is exposed to frontends via `getMaxFilterDepth()`, enabling a consistent enforcement boundary across the full stack.

### 8.3 Execution Row Limit
The engine enforces a configurable `max_rows` safety limit (default: 5000) on every generated query via `->limit()`. This prevents runaway queries from overwhelming the host application's memory and database connection pool, even when the host omits its own pagination.

### 8.4 RAM Crash Prevention via Pagination and Streaming
Host applications inherently lack the context to know if a dynamically generated report will evaluate to 50 rows or 5,000,000 rows. If an application blindly calls `->get()` on a massive dataset, the PHP process will crash due to memory exhaustion.
- **Paginated Generation**: The engine abstracts execution via `DynamicReport::generatePaginated($request, 50)`. This forces the host application into an O(1) memory complexity boundary, ensuring only exactly 50 rows are ever loaded into RAM simultaneously.
- **Cursor-Based CSV Streaming**: To facilitate massive data exports, the engine leverages Laravel's `cursor()` method combined with a Symfony `StreamedResponse`. The database cursor iterates results one row at a time via a server-side cursor, piping each row as CSV bytes directly over the HTTP connection. This maintains O(1) PHP memory complexity regardless of export size, enabling the secure export of gigabyte-scale CSV files without impacting backend server stability.

### 8.5 Model Visibility Control
The engine implements a multi-layered model visibility system to control which Eloquent models are available for reporting:
1. **Auto-Discovery (Default)**: When `reportable_models` config is empty, the engine scans `app_path()` via Symfony Finder to discover all Eloquent models automatically.
2. **Explicit Whitelist**: When `reportable_models` is populated, only listed models are available — auto-discovery is bypassed entirely.
3. **Model Restriction**: Models can be dynamically restricted at runtime via `restrictModel()`, removing them from the available model graph.
4. **Internal Model Exclusion**: The engine's own infrastructure models (`SavedReport`, `ReportLog`, `RestrictedModel`, `AttributeRestriction`, `VirtualAttribute`) are automatically excluded via the `INTERNAL_MODELS` constant, unless the `include_package_models` config is explicitly set to `true`.

---

## 9. UI-Agnostic Service Abstraction (The Plug-and-Play Paradigm)

A critical architectural evolution of the package is the complete decoupling of "glue logic" from the Host Application's HTTP controllers. 

### 9.1 The Problem with "Fat" Host Controllers
Historically, Host Applications were required to write hundreds of lines of boilerplate controller logic to translate frontend JSON payloads into the engine's strict AST DTOs, or to query the database to build security matrices. This violated the DRY principle and made implementing custom UIs in React, Vue, or Next.js cumbersome.

### 9.2 Package Service Encapsulation
To achieve a true "plug-and-play" architecture, all bridging logic is encapsulated within the core package via dedicated Services and Factories:
- **`ReportBuilderRequest::fromPayload()`**: Abstracted the complex mapping of nested frontend JSON into the strict `ReportRequest` AST, reducing the host's Report Builder controller to a single line of code.
- **`VirtualAttributeCompiler`**: Encapsulates the logic of transpiling a 2D visual builder payload into a raw scalar SQL subquery.
- **`VirtualAttributeManager`**: Centralizes usage tracking (via recursive JSON `LIKE` queries against saved payloads) and enforces strict deletion safety constraints, preventing the host app from accidentally breaking saved reports.
- **`GovernanceManager`**: Abstracts the querying and saving of the polymorphic Attribute-Level Security rules, allowing the host to fetch the "Security Matrix" without any knowledge of the underlying database schema.

**Why?**: By abstracting this logic into the `Nisalatp\DynamicReportGenerator\Services` namespace, Host Applications are reduced to pure HTTP routers. They catch the frontend payload, pass it directly to the package Service, and return the response. This guarantees flawless integration regardless of the UI framework utilized by the consuming application.
