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
- **Broker Pattern**: The `ReportMaker` engine acts as an intermediary (broker) for Schema Discovery. It abstracts away PHP Reflection and physical database schema lookups, allowing external decoupled frontends to dynamically query the available models and virtual attributes via standard APIs.
- **Service Provider Pattern**: Allows the package to inject its migrations, configurations, and core services into the host application seamlessly upon installation via Composer.

## 3.5 Security & Optimization Methodologies
To meet enterprise standards, specific methodologies were applied to the data extraction processes:
- **Strict DTO Validation**: Rather than relying on SQL sanitization libraries, the system implements rigid validation at the Data Transfer Object (DTO) instantiation phase. For example, the `Aggregate` DTO employs an immutable whitelist (`['SUM', 'COUNT', 'AVG', 'MIN', 'MAX']`) to actively reject unrecognized functions before they reach the database grammar compiler, eliminating SQL injection vectors.
- **Attribute Level Security (ALS) State Machine**: A polymorphic security layer natively embedded in the engine (illustrated in **Diagram 11: Attribute Restriction Component**). 

  ![Diagram 11: Attribute Restriction Component](./diagrams/11_attribute_restriction_component.puml)

  Instead of relying on frontend obfuscation, restricted attributes are physically scrubbed at the compilation level. The attribute security lifecycle follows a strict state machine (see **Diagram 9: Attribute Security State**):

  ![Diagram 9: Attribute Security State](./diagrams/9_attribute_security_state.puml)

  - **Unrestricted**: Default state.
  - **Masked**: Output is replaced with `***`, but backend mathematical operations are permitted.
  - **Blocked**: Output is replaced with `###`, and any attempt to use the attribute in a backend calculation throws a fatal `ReportMakerSecurityException` (as detailed in **Diagram 8: Security Activity Flow**), ensuring absolute data governance.

  ![Diagram 8: Security Activity Flow](./diagrams/8_security_activity_flow.puml)
- **Database Chunking & O(1) Memory Complexity**: The compilation of dynamic reports poses a severe risk of triggering Out-Of-Memory (OOM) fatal errors when processing millions of records. To mitigate this, the engine enforces memory boundaries using two methodologies:
  1. **Pagination Restrictions**: Abstracting output through `generatePaginated()` forces host applications to maintain a predictable, flat memory footprint for UI rendering.
  2. **Byte-Streaming via HTTP**: When exporting data, the engine utilizes Laravel's `chunk()` execution combined with Symfony `StreamedResponse`. This pipes the database results as raw CSV bytes directly over the network connection, bypassing PHP string memory buffering entirely and allowing gigabyte-scale data exports on heavily constrained infrastructure.
