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

### 3.1 Mapping the Relational Graph
The engine utilizes **PHP Reflection** to inspect the host application's Eloquent Models dynamically at runtime. It analyzes method return types (e.g., `BelongsTo`, `HasMany`) to discover foreign keys, local keys, and table relationships. These relationships are mapped into an adjacency list representing a **Directed Graph**.

### 3.2 Breadth-First Search (BFS)
When a user selects columns from different tables (e.g., pulling "Department Name" on a "User" report), the engine must figure out the `JOIN` path.
- The engine runs a **Breadth-First Search (BFS)** algorithm across the relational graph.
- BFS guarantees the shortest possible relational path between the Base Model and the Target Model.
- **Why?**: Generating the shortest path minimizes the number of `LEFT JOIN` clauses required, massively optimizing the resulting SQL query performance and eliminating circular join errors.

---

## 4. Virtual Attributes & Subquery Pushdown

The Virtual Attribute (VA) system allows administrators to attach calculated fields to models (e.g., "Total Spent" on a `User`).

### 4.1 Subquery Pushdown Optimization
A naive reporting engine would fetch all `User` records, load their related `Orders` into PHP memory, and use a `foreach` loop to sum the totals. This approach scales terribly and causes Out-Of-Memory (OOM) fatal errors on large datasets.
- **The Solution**: The engine employs **Subquery Pushdown**. Virtual Attributes are stored as raw SQL strings (e.g., `(SELECT SUM(amount) FROM orders WHERE user_id = users.id)`). The engine injects these strings directly into the `SELECT` clause using `DB::raw()`. 
- **Why?**: This offloads the computational heavy lifting to the Relational Database Management System (RDBMS). MySQL, Postgres, and SQLite execute C-level aggregations incredibly fast, maintaining an O(1) memory footprint in the PHP application regardless of the dataset size.

### 4.2 The Singleton Registry Cache
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
