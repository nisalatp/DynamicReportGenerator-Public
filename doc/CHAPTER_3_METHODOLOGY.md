# Chapter 3: Methodology

## 3.1 Overview
The development of the Dynamic Report Generator followed a structured Software Development Life Cycle (SDLC). This chapter outlines the methodological approaches, the chosen technology stack, and the architectural design patterns utilized to achieve the project's objectives.

## 3.2 Development Lifecycle: Agile Methodology
An Agile, iterative approach was adopted for this project. Given the complexity of building a compiler capable of parsing dynamic ASTs, the system was developed in iterative phases:
1. **Phase 1: Core Engine**: Building the strict AST DTOs and the base Query Builder traversal.
2. **Phase 2: Graph Resolution**: Implementing the BFS algorithm for dynamic Eloquent joins.
3. **Phase 3: Virtual Attributes**: Developing the Singleton Registry and subquery pushdown mechanism.
4. **Phase 4: Persistence & UI**: Integrating the database models and building the AlpineJS demonstration frontend.

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
- **Service Provider Pattern**: Allows the package to inject its migrations, configurations, and core services into the host application seamlessly upon installation via Composer.
