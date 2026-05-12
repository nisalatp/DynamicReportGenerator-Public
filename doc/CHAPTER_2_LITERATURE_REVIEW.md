# Chapter 2: Literature Review

## 2.1 Overview
The challenge of bridging the gap between non-technical end-users and complex relational databases has led to the development of various reporting architectures. This chapter reviews existing solutions, categorizing them into Enterprise Business Intelligence (BI) platforms and Framework-Native Administration Panels, and establishes the necessity for the Dynamic Report Generator.

## 2.2 Enterprise Business Intelligence Platforms
Platforms such as **Microsoft PowerBI**, **Tableau**, and **Metabase** represent the industry standard for enterprise data visualization. 
- **Strengths**: These tools offer unparalleled graphical capabilities, highly optimized data cubes, and extensive connectors for varied data sources.
- **Weaknesses**: They operate as entirely separate entities from the primary software application. Integrating a BI tool into a native web application (embedded analytics) is notoriously difficult, often requiring iframe workarounds, separate authentication layers (SSO/SAML), and massive architectural overhead involving ETL (Extract, Transform, Load) pipelines. They are often overkill for mid-sized web applications that simply require dynamic tabular reporting.

## 2.3 Framework-Native Administration Panels
Within the PHP/Laravel ecosystem, tools like **Laravel Nova** and **Filament PHP** are highly popular for rapid backend scaffolding.
- **Strengths**: They are tightly integrated into the codebase. Because they utilize the existing Eloquent ORM, they do not require separate database connections or authentication layers.
- **Weaknesses**: These tools are primarily designed for CRUD (Create, Read, Update, Delete) operations. While they offer basic filtering, they struggle with complex, multi-layered aggregations across distant relationships. For example, generating a single report that sums the total value of orders, grouped by a user's geographical region, while filtering out specific product categories, typically requires a developer to write a custom, hardcoded reporting class. They lack a visual "Query Builder" capable of arbitrary, multi-table traversals.

## 2.4 The Developer Bottleneck & The Architectural Gap
The literature reveals a distinct operational gap in modern enterprises: "The Developer Bottleneck." When non-technical users cannot use complex BI tools and native framework tools are too rigid, the responsibility of generating custom, multi-table reports falls directly on software engineers. This is an incredibly expensive misuse of development resources. 

Web applications require a tool that democratizes data access. The ideal solution must possess the deep integration of a native package (like Filament), the relational querying power of a standalone BI tool (like Metabase), and a **UX Abstraction layer** that allows engineers to pre-configure complex joins so that basic floor workers can generate their own reports with simple clicks.

## 2.5 Theoretical Foundations for the Proposed Solution
To resolve this gap, the Dynamic Report Generator relies on two critical computer science theories:
1. **Compiler Theory (AST)**: By enforcing an Abstract Syntax Tree structure, the system can parse arbitrary frontend states into a safe, predictable compiler target, mirroring how language compilers (like GCC or LLVM) separate frontend syntax from backend machine code.
2. **Graph Theory**: Relational databases are inherently graph structures. By treating tables as Nodes and Foreign Keys as Edges, the system can employ a Breadth-First Search (BFS) algorithm to automatically deduce the shortest path between any two tables, thereby removing the burden of `JOIN` logic from the end-user. The engine extends classical BFS with **bidirectional graph construction** — synthesizing missing reverse edges so that relationships declared in only one direction can still be traversed both ways.
