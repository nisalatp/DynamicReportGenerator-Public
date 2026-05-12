# Software Requirements Specification (SRS)
## Dynamic Report Generator (Laravel Package)

---

## 1. Introduction

### 1.1 Purpose
The purpose of this document is to detail the comprehensive feature set, architectural constraints, and system capabilities of the **Dynamic Report Generator**. This system is designed as an installable, enterprise-grade Laravel package that provides host applications with a decoupled, high-performance reporting engine.

### 1.2 Scope
The Dynamic Report Generator allows end-users to visually orchestrate complex database queries without writing SQL. It transpiles JSON-based payloads into an Abstract Syntax Tree (AST), dynamically resolves Eloquent relationships, and executes optimized database queries. It includes persistent report lifecycle management, a Virtual Attribute extensibility layer, and granular access control.

---

## 2. Overall Description

### 2.1 User Roles
1. **End-User (Reporter)**: Anyone from basic departmental floor workers to executives who need self-serve data. They use the visual interface to select simple checkboxes (Virtual Attributes) without needing any SQL or database relationship knowledge.
2. **Admin / Developer**: Software engineers or power users who use the Virtual Attribute Builder to pre-configure complex joins and subqueries, shielding the End-Users from database complexity and thereby eliminating the "Developer Bottleneck" for daily reporting.
3. **System Administrator**: Manages report assignments and reviews audit logs for compliance.

### 2.2 Operating Environment
- **Framework**: Laravel 10.x / 11.x
- **Language**: PHP 8.2+
- **Database**: MySQL, PostgreSQL, or SQLite
- **Architecture Paradigm**: Client-Server, API-Driven, Decoupled Package.

---

## 3. System Features (Functional Requirements)

### 3.1 Dynamic Query Engine
The core compiler that translates frontend requests into secure database queries.
- **FR 3.1.1 Abstract Syntax Tree (AST) Parsing**: The engine accepts strict Data Transfer Objects (`ReportRequest`) containing `Attributes`, `FilterGroups`, and `Aggregates` instead of loose arrays.
- **FR 3.1.2 Auto-Join Resolution (Bidirectional Graph Theory)**: Users only need to specify the Base Model and Target Models. The engine uses PHP Reflection to map Eloquent relationships into a Graph network via two-phase **bidirectional discovery** — first scanning forward-declared relationships, then synthesizing missing reverse edges by inverting relationship types. It executes a **Breadth-First Search (BFS)** algorithm on this bidirectional graph to automatically determine the shortest relational path and apply the necessary `LEFT JOIN` clauses. Each join step carries a `direction` metadata field (`'forward'` or `'reverse'`) indicating how the edge was discovered. The bidirectional graph is cached in-memory for performance.
- **FR 3.1.3 Advanced Filtering**: Supports infinitely nested `AND/OR` filter conditions (`WHERE` clauses) and post-aggregation filtering (`HAVING` clauses).
- **FR 3.1.4 Aggregation & Grouping**: Natively supports SQL aggregates (`COUNT`, `SUM`, `AVG`, `MIN`, `MAX`) grouped by any selected dimension.

### 3.2 Virtual Attribute System
A critical **UX Abstraction layer** that allows engineers to attach complex, calculated subqueries (e.g., a "Total Value" field joining 3 tables) to an Eloquent model. To the non-technical End-User, this appears simply as a single, selectable column in the UI, completely hiding the relational complexity.
- **FR 3.2.1 Virtual Attribute Registry**: A Singleton service that loads registered SQL fragments into memory once per request lifecycle, injecting them into the Query Builder when requested by the AST.
- **FR 3.2.2 No-Code Visual Builder**: A UI tool allowing administrators to define aggregations across related tables (e.g., "Total Order Value for a User") through dropdown selections. The UI transpiles these selections into raw SQL.
- **FR 3.2.3 Advanced SQL Mode**: Allows Developers to inject raw, highly-optimized SQL subqueries directly into the Registry for maximum flexibility.
- **FR 3.2.4 Dependency Resolution**: Virtual Attributes automatically declare their target model dependencies, ensuring the core engine executes the necessary `JOIN` operations before running the subquery, entirely behind the scenes.

### 3.3 Report Persistence & Lifecycle Management
Provides mechanisms to store and retrieve report configurations.
- **FR 3.3.1 Configuration Saving**: Converts the active AST back into a JSON string and persists it to the `dynamic_saved_reports` database table.
- **FR 3.3.2 UI Rehydration (Loading)**: Fetches the strict AST from the database and maps the complex, nested JSON back into a flat state digestible by AlpineJS/Vue/React frontend interfaces.
- **FR 3.3.3 Headless Execution**: Allows the API to fetch a saved report by ID and execute it instantly on the backend (`DynamicReport::loadAndGenerate()`) without rendering or loading the frontend UI blocks.

### 3.4 Access Control & Delegation
Manages ownership and sharing of generated reports.
- **FR 3.4.1 Ownership**: Reports are tied to the `user_id` of the creator.
- **FR 3.4.2 Report Assignment**: Owners and Admins can assign (`attach()`) or unassign (`detach()`) reports to other users via the `dynamic_report_user` pivot table, allowing collaborative execution.

### 3.5 Auditing & Compliance
- **FR 3.5.1 Automated Logging**: Every significant lifecycle event (`created`, `updated`, `executed`, `assigned`, `unassigned`, `deleted`, `error`) is automatically recorded in the `dynamic_report_logs` table.
- **FR 3.5.2 Error Tracing**: Failed executions automatically log the exception message and stack trace for administrator review.

### 3.6 Data Governance & Security
Maintains a strict, polymorphic Attribute-Level Security (ALS) firewall embedded directly inside the SQL compiler.
- **FR 3.6.1 Dynamic Subject Resolution**: Supports polymorphic security rules assigned to any host entity (Users, Roles). Checks `auth()->user()` or custom `DynamicReportSubject` interface implementations to aggregate active rules dynamically.
- **FR 3.6.2 Masked Attributes (`***`)**: Restricts the viewing of sensitive columns. If a user selects a masked attribute, the engine returns `***`. The attribute remains fully accessible for backend calculations (`WHERE`, `GROUP BY`).
- **FR 3.6.3 Blocked Attributes (`###`)**: Strictly prohibits use. If detected in `filters`, `aggregations`, or `groupBys`, the engine halts execution and throws a `ReportMakerSecurityException`. Blocked columns are completely excluded from Schema Discovery (`getModelAttributes()`).
- **FR 3.6.4 Dynamic Model Discovery & Restriction**: Discovers all active Eloquent models automatically and supports explicit whole-model exclusions via the `dynamic_restricted_models` table.

---

## 4. Non-Functional Requirements

### 4.1 Performance & Efficiency
- **Subquery Pushdown**: Virtual Attributes and Aggregations are strictly processed at the RDBMS layer (Hardware speed). The system strictly avoids loading Eloquent Collections into PHP RAM for processing, ensuring O(1) memory complexity regardless of dataset size.
- **Registry Caching**: The Singleton pattern ensures database calls to fetch Virtual Attributes only happen once per HTTP lifecycle, preventing N+1 queries.

### 4.2 Scalability & Extensibility
- **Schema-less Configuration**: The `SavedReport` payload relies on JSON column types, allowing the AST structure to evolve without requiring database migrations.
- **Framework Agnostic UI**: Because the engine only communicates via strict JSON REST APIs, the frontend can be built in AlpineJS, React, Vue, or completely decoupled as a separate microservice.

### 4.3 Security
- **Delegated Authentication**: The package itself does not enforce a specific Auth guard. It relies on the Host Application's middleware (e.g., Laravel Sanctum or Session auth) to authenticate the API endpoints, ensuring seamless integration into any existing enterprise environment.
- **SQL Injection Prevention**: All queries, including Virtual Attribute injections, are passed through Laravel's PDO parameter binding and grammar wrappers.
