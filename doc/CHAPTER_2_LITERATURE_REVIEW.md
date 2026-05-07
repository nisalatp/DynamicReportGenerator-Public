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

## 2.4 The Architectural Gap
The literature reveals a distinct gap: Web applications require a tool that possesses the deep integration and simplicity of a native package (like Filament) but contains the dynamic, relational querying power of a standalone BI tool (like Metabase).

## 2.5 Theoretical Foundations for the Proposed Solution
To resolve this gap, the Dynamic Report Generator relies on two critical computer science theories:
1. **Compiler Theory (AST)**: By enforcing an Abstract Syntax Tree structure, the system can parse arbitrary frontend states into a safe, predictable compiler target, mirroring how language compilers (like GCC or LLVM) separate frontend syntax from backend machine code.
2. **Graph Theory**: Relational databases are inherently graph structures. By treating tables as Nodes and Foreign Keys as Edges, the system can employ a Breadth-First Search (BFS) algorithm to automatically deduce the shortest path between any two tables, thereby removing the burden of `JOIN` logic from the end-user.
