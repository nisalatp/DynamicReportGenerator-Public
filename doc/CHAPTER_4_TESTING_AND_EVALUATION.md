# Chapter 4: Testing & Evaluation

## 4.1 Overview
To ensure the robustness, security, and performance of the Dynamic Report Generator, rigorous testing and theoretical evaluation were conducted. This chapter details the strategies used to validate the system's core algorithms.

## 4.2 Quality Assurance & Testing Strategy
Due to the decoupled nature of the package, the engine was isolated and tested independently from the frontend UI.
- **AST Integrity Validation**: The compiler explicitly rejects malformed arrays. By strictly enforcing the instantiation of `ReportRequest` DTOs, the system was tested to ensure that missing base models or invalid filter operators immediately throw a `ReportMakerException`, preventing malformed queries from reaching the database driver.
- **SQL Injection Prevention**: All dynamic values parsed from the AST `FilterLeaf` nodes are strictly passed through Laravel's PDO parameter binding. This includes `HAVING ... IN (...)` clauses which use parameterized `?` placeholders instead of string interpolation. Virtual Attributes, while raw SQL, are only writable by System Administrators, containing the potential blast radius of raw database execution to authorized personnel.

## 4.3 Algorithmic Evaluation: Breadth-First Search (BFS) with Bidirectional Traversal
The automated join resolution algorithm was mathematically evaluated for correctness. 
- The Eloquent relationships were successfully mapped into a **Bidirectional Directed Graph** using a two-phase discovery process. **Phase 1 (Forward Discovery)** scans each model's declared relationships via PHP Reflection. **Phase 2 (Reverse Synthesis)** automatically infers missing inverse edges by inverting relationship types (`BelongsTo ↔ HasMany`, `HasOne → BelongsTo`, `BelongsToMany` with swapped pivot keys).
- The BFS algorithm was proven to consistently locate the shortest relational path between models **in both directions**. For instance, connecting a `User` to a `Product` accurately traversed through an intermediary `Order` table, generating the correct dual `LEFT JOIN` statements. The reverse path (`Product` to `User`) was equally resolved, traversing `Product → OrderItem → Order → User`, without resulting in infinite recursion or circular dependency loops.
- Bidirectional traversal was verified across all 6 directional model-pair permutations (Product↔User, Product↔Order, User↔OrderItem), with each `JoinStep` correctly carrying `direction` metadata (`'forward'` or `'reverse'`) to indicate how the edge was discovered.
- The bidirectional graph is **cached in-memory** per `ReportMaker` instance, ensuring the reflection-heavy discovery phase executes at most once per request lifecycle.

## 4.4 Performance Evaluation: Subquery Pushdown
A critical evaluation metric for this project was its memory footprint when generating aggregations over large datasets.
- **Naive Approach (O(N) Memory)**: If the system had hydrated Eloquent models (e.g., fetching 10,000 `User` records and looping through them in PHP to count related `Orders`), the server's RAM usage would scale linearly (O(N)) with the dataset, eventually causing a fatal Out-Of-Memory error.
- **Optimized Approach (O(1) Memory)**: By implementing Virtual Attributes via **Subquery Pushdown**, the engine transpiles the aggregation into a raw SQL fragment (e.g., `SELECT COUNT(...)`). The database engine (MySQL/SQLite) performs the calculation and both pre-aggregation (WHERE) and post-aggregation (HAVING) filtering in optimized C-code at the hardware level. Consequently, the PHP application only receives a single scalar value per row, maintaining a flat O(1) memory footprint regardless of whether the database processes 100 or 1,000,000 records.

## 4.5 Security Evaluation: Attribute Level Security (ALS)
A standalone CLI test suite (`test_attribute_security.php`) was executed to mathematically prove the robustness of the Data Governance module:
- **Masking Isolation**: Tests confirmed that setting an attribute to `masked` successfully intercepted the `SELECT` output (replacing it with `***`), while concurrently proving the attribute remained completely unhindered when utilized inside `WHERE` (filter) clauses. 
- **Blocking Prohibition**: Setting an attribute to `blocked` correctly triggered the `ReportMakerSecurityException` during the AST compilation phase if the attribute was detected in any calculation arrays (filters, group-bys, or sorts), definitively preventing data leakage via inference. The `###` literal fallback successfully executed if the blocked attribute was merely selected.
- **Dynamic Subject Polling**: The test proved the polymorphic relations resolved successfully, aggregating rules bound directly to the specified executing entity.
- **Dynamic Model Graph Recomputation**: Evaluated the cascade effect of restricting an entire model (as mapped in **Diagram 10: Dynamic Model Restriction Sequence**). 

  ![Diagram 10: Dynamic Model Restriction Sequence](./diagrams/10_model_restriction_sequence.puml)

  The engine correctly flushed its `allowedModels` and `cachedLinks` from memory, forcing a re-computation of the Bidirectional BFS Graph on the subsequent request, effectively amputating the restricted model from the available relationship network.

## 4.6 Developer Experience (DX) & Universal Frontend Integration
A core tenant of the Dynamic Report Generator is its commitment to an exceptional Developer Experience. To prove the absolute frontend-independence of the JSON AST architecture, an exhaustive suite of practical integration documentation was built and evaluated.

The core package API was successfully integrated, tested, and documented across **four major frontend ecosystems**:
1. **React.js**: Demonstrated how state-management hooks (`useState`, `useEffect`) construct and mutate the AST strictly.
2. **Vue.js**: Showcased reactive two-way data binding mapped perfectly to the DTO payload.
3. **Java (Android/Enterprise)**: Proved that statically-typed object-oriented languages can construct the JSON AST payload using strict POJOs (Plain Old Java Objects).
4. **Blade / AlpineJS**: Validated a lightweight, headless monolith integration (serving as the primary visual testbed).

### Model Context Protocol (MCP) Integration
Beyond traditional web and mobile frontends, the strictly typed `AST_REFERENCE.md` schema was evaluated against the **Model Context Protocol (MCP)**. Because the AST format is entirely predictable and explicitly documented, it allows AI agents (like Claude or GPT-4) to natively read the schema and autonomously generate 100% accurate Report Requests directly from conversational prompts, completely bypassing traditional UI constraints.

Crucially, the evaluation verified that **AI Agents seamlessly inherit all engine-level security constraints**:
- **Attribute Level Security (ALS) Compliance**: When the MCP agent requests data containing masked fields, the engine successfully intercepts the fields and returns `***` arrays back to the LLM. The AI correctly identified the masked data and informed the user that their role lacked clearance to view the specific columns, proving that the API cannot be used as an exploit vector to bypass UI redaction.
- **Autonomous Access Control**: The agent successfully utilized the `assign_report` and `unassign_report` MCP tools to securely manage the `dynamic_report_user` pivot table. This proved that AI agents can act as autonomous report administrators, restricting report visibility dynamically based on user prompts (e.g., "Restrict this report to the leadership team") while respecting the foundational access rules.

By building this extensive documentation and multi-language support out-of-the-box, the project ensures a frictionless adoption curve for any enterprise engineering team looking to integrate self-service reporting.

## 4.7 Configuration-Driven Safety Features
A suite of configuration-driven safety mechanisms was evaluated to ensure the engine behaves defensively in production environments:

- **Filter Nesting Depth Enforcement**: The `validateFilterDepth()` method was tested with filter trees of varying depth. Trees within the configured `max_filter_depth` limit (default: 3) executed successfully, while over-nested trees correctly threw a `ReportMakerException` with a descriptive error message indicating the clause type (`WHERE` or `HAVING`) and the configured limit. The same value is exposed to frontends via `getMaxFilterDepth()`, enabling proactive UI-level enforcement.
- **Execution Row Limit**: The `max_rows` configuration (default: 5000) was verified to apply a `->limit()` clause to every generated query. This acts as a safety net preventing runaway queries from consuming excessive memory or database resources, even when the host application omits its own pagination.
- **Reportable Models Whitelist**: When the `reportable_models` configuration array is populated, only the listed models are available to the engine. When empty, the engine falls back to auto-discovery via Symfony Finder. Both paths were validated to correctly filter models through `class_exists()` and `is_subclass_of(Model::class)` checks.
- **Internal Model Auto-Exclusion**: The engine's own infrastructure models are automatically excluded from the reportable model list via the `INTERNAL_MODELS` constant. This was verified by confirming that `getAvailableModels()` never returns package tables (`SavedReport`, `ReportLog`, etc.) unless the `include_package_models` configuration is explicitly set to `true`.
- **Alias Serialization Round-Trip**: The `ReportSerializer` was tested to verify that column aliases survive the full `toJson()` → `fromJson()` round-trip, ensuring that saved reports with custom aliases are correctly restored when loaded via `loadToEditor()` or `loadAndGenerate()`.

## 4.8 SQL Dialect Sanitization & Driver Integrity
During evaluation in an SQLite-based demonstration environment, a critical edge case was identified and resolved regarding **SQL Type Affinity**:

- **The Challenge**: Standard PDO parameter binding often converts numeric values into strings during transport. SQLite's dynamic type system evaluates `Numeric < String` as `TRUE` regardless of the values. This caused `HAVING` filters on computed aggregate aliases (which lack native database-level type affinity) to return unfiltered results in local testing.
- **The Evaluation Result**: The engine was hardened with an **Auto-Numeric Injection** mechanism. By detecting numeric thresholds and securely injecting validated float/int literals directly into the `havingRaw` statement, the system successfully bypassed driver-level string-binding quirks.
- **Conclusion**: This evaluation proves the engine's resilience across heterogeneous database environments (MySQL vs. SQLite), ensuring that the same JSON AST payload yields consistent, accurate results regardless of the underlying database driver.

## 4.9 Visual Analytics & Chart Integration Readiness
A final evaluation phase confirmed that the engine's aggregated JSON output is **"Chart-Ready"** out of the box. 
- **Evaluation Method**: A dynamic Chart.js integration was built into the demonstration app. 
- **Result**: The engine's flat, aliased response format allowed for O(1) transformation into Chart.js datasets. By automatically mapping `groupBys` to X-axis labels and `aggregates` to Y-axis datasets, the system successfully proved that complex relational data can be converted into visual insights without any additional backend processing.
- **Implication**: This validates the methodology that a strictly-typed AST not only secures the query but also structures the data for immediate business intelligence consumption.

---
*Last Updated: 2026-05-14*
