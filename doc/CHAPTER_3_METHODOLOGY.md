# Chapter 3: Methodology

## 3.1 Overview
The development of the Dynamic Report Generator followed a structured Software Development Life Cycle (SDLC). This chapter outlines the methodological approaches, the chosen technology stack, and the architectural design patterns utilized to achieve the project's objectives.

## 3.2 Development Lifecycle: Agile Methodology
An Agile, iterative approach was adopted for this project. Given the complexity of building a compiler capable of parsing dynamic ASTs, the system was developed in iterative phases:
1. **Phase 1: Core Engine**: Building the strict AST DTOs and the base Query Builder traversal.
2. **Phase 2: Bidirectional Graph Resolution**: Implementing the two-phase BFS algorithm for dynamic Eloquent joins — forward relationship discovery via Reflection, followed by automatic reverse edge synthesis for bidirectional traversal.
3. **Phase 3: Virtual Attributes**: Developing the Singleton Registry and subquery pushdown mechanism.
4. **Phase 4: Persistence & UI**: Integrating the database models and building the AlpineJS demonstration frontend.
5. **Phase 5: Data Governance & Security**: Building the polymorphic Attribute Level Security (ALS) firewall directly into the SQL AST compiler.
6. **Phase 6: Hardening & Safety Limits**: Implementing configuration-driven safety boundaries (execution row limits, filter nesting depth validation, internal model auto-exclusion, and reportable model whitelisting).

This iterative approach allowed for continuous testing of the SQL outputs at each phase before advancing to more complex relational structures.

## 3.3 Technology Stack Justification
- **Backend Framework: Laravel 10.x / 11.x**: Chosen for its robust Service Provider architecture, the power of its Query Builder grammar, and its enterprise prevalence. 
- **Language: PHP 8.2+**: Utilized specifically for its strict typing, readonly properties, and advanced Reflection API, which are mandatory for parsing strict DTOs and mapping the Eloquent relational graph.
- **Frontend Demonstration: AlpineJS & Bootstrap 5**: Chosen to prove that the backend API is entirely decoupled. AlpineJS provides the necessary reactivity to manage the complex, nested JSON state of the visual builder without the heavy compilation steps required by React or Vue.

## 3.4 Architectural Design Patterns
To ensure enterprise-grade maintainability, several Gang of Four (GoF) design patterns were heavily utilized:
- **Composite Pattern**: Used within the AST. A `FilterGroup` contains an array of `FilterNode` interfaces (which can be either `FilterLeaf` or another `FilterGroup`), allowing the engine to traverse infinitely nested SQL conditions using recursive algorithms.
- **Singleton Pattern**: The `VirtualAttributeRegistry` is instantiated as a Singleton. This ensures the application loads the raw SQL strings from the database only once per HTTP request, preventing N+1 query performance bottlenecks.
- **Facade Pattern**: The `DynamicReport` Facade abstracts the complex instantiation of the core engine, exposing a clean, static interface for developers to interact with the package.
- **Builder Pattern**: The `ReportBuilder` and `FilterBuilder` classes implement a fluent builder interface, allowing developers to construct complex `ReportRequest` DTOs programmatically with method chaining (e.g., `ReportBuilder::forModel(User::class)->select(...)->filter(...)->build()`). This pattern is also used in `VirtualAttributeBuilder` for registering Virtual Attributes via a declarative API.
- **Broker Pattern**: The `ReportMaker` engine acts as an intermediary (broker) for Schema Discovery. It abstracts away PHP Reflection and physical database schema lookups, allowing external decoupled frontends to dynamically query the available models and virtual attributes via standard APIs.
- **Service Provider Pattern**: Allows the package to inject its migrations, configurations, and core services into the host application seamlessly upon installation via Composer.
- **UI-Agnostic Service Layer**: Complex business logic (AST compilation, security matrix resolution, dynamic usage tracking) is strictly extracted into independent package `Services`. This guarantees that Host Applications (whether built in Vue, React, or Blade) act purely as HTTP routers, ensuring true "plug-and-play" capability without rewriting massive backend controllers.

## 3.5 Security & Optimization Methodologies
To meet enterprise standards, specific methodologies were applied to the data extraction processes:
- **Strict DTO Validation**: Rather than relying on SQL sanitization libraries, the system implements rigid validation at the Data Transfer Object (DTO) instantiation phase. For example, the `Aggregate` DTO employs an immutable whitelist (`['SUM', 'COUNT', 'AVG', 'MIN', 'MAX']`) to actively reject unrecognized functions before they reach the database grammar compiler, eliminating SQL injection vectors. Similarly, the `FilterLeaf` DTO validates operators against a strict whitelist and the `Sort` DTO restricts directions to `['ASC', 'DESC']`.
- **Parameterized SQL Bindings**: All dynamic values in filter clauses — including `HAVING ... IN (...)` expressions — use PDO parameterized bindings (`?` placeholders) instead of string interpolation, ensuring SQL injection prevention even in complex aggregate filter paths.
- **Filter Nesting Depth Validation**: To prevent abuse through excessively complex filter trees, the engine enforces a configurable `max_filter_depth` limit (default: 3). The `validateFilterDepth()` method recursively measures the nesting depth of `FilterGroup` nodes and throws a `ReportMakerException` if the configured limit is exceeded. This same limit is exposed to frontends via `getMaxFilterDepth()` so UIs can proactively restrict nesting in the builder interface.
- **Attribute Level Security (ALS) State Machine**: A polymorphic security layer natively embedded in the engine (illustrated in **Diagram 11: Attribute Restriction Component**). 

  ![Diagram 11: Attribute Restriction Component](./diagrams/11_attribute_restriction_component.puml)

  Instead of relying on frontend obfuscation, restricted attributes are physically scrubbed at the compilation level. The attribute security lifecycle follows a strict state machine (see **Diagram 9: Attribute Security State**):

  ![Diagram 9: Attribute Security State](./diagrams/9_attribute_security_state.puml)

  - **Unrestricted**: Default state.
  - **Masked**: Output is replaced with `***`, but backend mathematical operations are permitted.
  - **Blocked**: Output is replaced with `###`, and any attempt to use the attribute in a backend calculation throws a fatal `ReportMakerSecurityException` (as detailed in **Diagram 8: Security Activity Flow**), ensuring absolute data governance.

  ![Diagram 8: Security Activity Flow](./diagrams/8_security_activity_flow.puml)

- **Internal Model Auto-Exclusion**: The engine's own infrastructure tables (`SavedReport`, `ReportLog`, `RestrictedModel`, `AttributeRestriction`, `VirtualAttribute`) are automatically excluded from the reportable model list via the `INTERNAL_MODELS` constant. This prevents accidental exposure of security and audit tables in the UI. The behavior can be overridden by setting `include_package_models` to `true` in the package configuration.
- **Configurable Model Whitelisting**: When the `reportable_models` configuration array is populated, it acts as an explicit whitelist — only listed models are available to the engine. When left empty (default), the engine auto-discovers all Eloquent models by scanning the application's model directories. This dual behavior gives administrators full control over the engine's visibility into the data layer.
- **Execution Row Limit Enforcement**: The engine enforces a configurable `max_rows` limit (default: 5000) on all generated queries via `->limit()`. This safety net prevents runaway queries from overwhelming the host application's memory and database connection pool.
- **Memory-Safe CSV Streaming via Cursor**: When exporting data, the engine utilizes Laravel's `cursor()` method combined with Symfony `StreamedResponse`. The cursor uses a server-side database cursor to iterate results one row at a time, piping CSV bytes directly over the HTTP connection. This maintains O(1) PHP memory complexity regardless of export size, enabling gigabyte-scale data exports on heavily constrained infrastructure.
- **Paginated Execution**: Abstracting output through `generatePaginated()` forces host applications to maintain a predictable, flat memory footprint for UI rendering, processing only a fixed number of rows (default: 50) per request.
- **Universal AI Governance Enforcement**: Because the security policies (ALS masking and Report Assignment scopes) are executed strictly at the compiler and persistence layers, they form an absolute perimeter. This guarantees that when AI Agents connect to the platform via the Model Context Protocol (MCP), they inherently inherit the exact permissions and masked views of the human user executing the prompt. There is no separate "AI API"—the agent is bound by the exact same engine constraints as the frontend UI.
