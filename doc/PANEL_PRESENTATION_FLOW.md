# End-User Report Creation Flow (Engineering Panel Guide)

This document maps the exact lifecycle of a report, from the moment a non-technical end-user clicks a button in the browser, down to the C-level SQL execution in the database. It explicitly links the user's actions to the corresponding files and functions within the Dynamic Report Generator package, providing a definitive roadmap for an engineering panel evaluation.

---

## The Scenario
**The End-User (e.g., a Logistics Coordinator)** wants a report showing the "Total Spend" of users, grouped by their geographical "City", but only for users who have spent more than $1,000. They do not know SQL, nor do they know that "City" belongs to an `Addresses` table, while "Total Spend" is an aggregation of the `Orders` table.

### Step 1: UI Selection (The UX Abstraction)
The user opens the Report Builder UI. 
1. They select `User` as the Base Model.
2. They check the `City` column.
3. They check the `Total Spend` Virtual Attribute checkbox.
4. They add a filter: `Total Spend > 1000`.

**Implementation Details:**
- **File**: `demo-app/resources/views/builder.blade.php`
- **Mechanism**: The frontend uses AlpineJS to track these clicks in a flat JavaScript array. The complex 3D relational graph is completely abstracted away from the user.

---

### Step 2: DTO Translation (The Safety Boundary)
The user clicks "Generate Report". AlpineJS sends a JSON POST request to the server. The host application catches this JSON and translates it into a strict Abstract Syntax Tree (AST) using strongly typed Data Transfer Objects (DTOs).

**Implementation Details:**
- **File**: `demo-app/app/Http/Controllers/ReportBuilderController.php`
- **Function**: `buildReportRequest()`
- **Mechanism**: The controller maps the loose JSON array into a `ReportRequest` object (containing `Attribute`, `FilterGroup`, and `FilterLeaf` instances). If the user manipulated the JSON to include invalid operators, PHP's strict typing throws an exception here, eliminating SQL injection vectors before the engine even boots.

---

### Step 3: Engine Ingestion, Security & Dependency Extraction
The host application passes the safe `ReportRequest` AST into the Dynamic Report Engine. The engine first validates security constraints, then scans the AST for any selected Virtual Attributes (like the user's requested `Total Spend`). 

**Implementation Details:**
- **File**: `fyp/public/src/ReportMaker.php`
- **Functions**: `ensureModelAllowed()`, `resolveAttributeRestrictions()`, `validateSecurity()`, `validateFilterDepth()`, `extractVirtualAttributeDependencies()`
- **Mechanism**: 
  1. The engine verifies the base model is allowed (not restricted, not an internal package model, and present in the whitelist if configured).
  2. It resolves ALS restrictions for the current user's subjects.
  3. It validates that no blocked attributes are used in filters, sorts, or aggregates.
  4. It validates that the filter nesting depth does not exceed `max_filter_depth` (default: 3).
  5. It recognizes that `Total Spend` is a Virtual Attribute, queries the Singleton `VirtualAttributeRegistry` to fetch the raw SQL fragment, and notes that this subquery depends on the `Orders` table. It dynamically adds `Orders` to the list of tables that need to be joined.

---

### Step 4: BFS Graph Auto-Joining (The Algorithm)
The engine now knows it needs to query the `Users` table, but it also needs to join the `Addresses` table (for the "City" column) and the `Orders` table (for the "Total Spend" subquery dependency). 

**Implementation Details:**
- **File**: `fyp/public/src/ReportMaker.php`
- **Functions**: `discoverLinks()`, `getForwardRelations()`, `getReverseRelations()`, `findShortestPath()`, and `planJoins()`
- **Mechanism**: 
  1. The engine first checks its **in-memory cache** for the bidirectional graph. If not cached, it proceeds to Phase 1.
  2. **Phase 1 — Forward Discovery (`getForwardRelations`)**: Uses **PHP Reflection** to read the Eloquent models in the host application, mapping their methods (`hasMany`, `belongsTo`) into an adjacency list (a Directed Graph). Each discovered relationship is tagged with `direction: 'forward'`.
  3. **Phase 2 — Reverse Synthesis (`getReverseRelations`)**: For every forward edge A→B where B→A does not already exist, the engine **synthesizes the inverse edge** by inverting the relationship type (`BelongsTo ↔ HasMany`, `HasOne → BelongsTo`, `BelongsToMany` with swapped pivot keys). These edges are tagged with `direction: 'reverse'`. This ensures the graph is **fully bidirectional**.
  4. The combined graph is cached in memory to avoid repeated reflection.
  5. It runs a **Breadth-First Search (BFS)** algorithm on the bidirectional graph to find the shortest relational path from `Users` to `Addresses`, and `Users` to `Orders`. The BFS can now traverse relationships in **either direction**, even if only one side was explicitly declared.
  6. It automatically generates the required `LEFT JOIN` clauses for the Laravel Query Builder. Each `JoinStep` carries a `direction` field so the frontend can visualize which edges were forward-declared and which were inferred. The user never had to write a `JOIN` statement.

---

### Step 5: Subquery Pushdown (The Optimization)
The engine constructs the `SELECT` and `WHERE` clauses. Instead of querying all the user's orders and running a `foreach` loop in PHP to calculate the total spend, it pushes the logic down to the database.

**Implementation Details:**
- **File**: `fyp/public/src/ReportMaker.php`
- **Functions**: `buildInnerQuery()`, `applyFilters()`
- **Mechanism**: When the compiler hits the `Total Spend` attribute, it injects the raw SQL fragment (e.g., `(SELECT SUM(amount) FROM orders WHERE orders.user_id = users.id)`) directly into the Query Builder using `DB::raw()`. It does the same for the filter (`HAVING Total Spend > 1000`).

---

### Step 6: Query Execution & Response
The engine finalizes the Laravel Query Builder instance, applies the configurable `max_rows` safety limit, and the host application executes the query against the RDBMS. 

**Implementation Details:**
- **File**: `fyp/public/src/ReportMaker.php`
- **Function**: `generate()` returns an `Illuminate\Database\Query\Builder`.
- **Mechanism**: Before returning, the engine applies `->limit(max_rows)` as a safety net (default: 5000). Because of the Subquery Pushdown, MySQL/SQLite executes the C-level aggregations directly on the database hardware. The database returns a flat, paginated array of results. The PHP application maintains an O(1) memory footprint because it never hydrated thousands of Eloquent models. The array is returned as JSON to the frontend, and the End-User instantly sees their completed report.
  
  Any masked attributes (e.g., `email` for a Data Analyst role) appear as `***` in the results, while blocked attributes appear as `###` — all enforced at the SQL compilation level, not the frontend.
