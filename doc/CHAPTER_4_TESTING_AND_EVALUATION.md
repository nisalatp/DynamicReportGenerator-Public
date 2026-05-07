# Chapter 4: Testing & Evaluation

## 4.1 Overview
To ensure the robustness, security, and performance of the Dynamic Report Generator, rigorous testing and theoretical evaluation were conducted. This chapter details the strategies used to validate the system's core algorithms.

## 4.2 Quality Assurance & Testing Strategy
Due to the decoupled nature of the package, the engine was isolated and tested independently from the frontend UI.
- **AST Integrity Validation**: The compiler explicitly rejects malformed arrays. By strictly enforcing the instantiation of `ReportRequest` DTOs, the system was tested to ensure that missing base models or invalid filter operators immediately throw a `ReportMakerException`, preventing malformed queries from reaching the database driver.
- **SQL Injection Prevention**: All dynamic values parsed from the AST `FilterLeaf` nodes are strictly passed through Laravel's PDO parameter binding. Virtual Attributes, while raw SQL, are only writable by System Administrators, containing the potential blast radius of raw database execution to authorized personnel.

## 4.3 Algorithmic Evaluation: Breadth-First Search (BFS)
The automated join resolution algorithm was mathematically evaluated for correctness. 
- The Eloquent relationships were successfully mapped into a Directed Graph using PHP Reflection. 
- The BFS algorithm was proven to consistently locate the shortest relational path between models. For instance, connecting a `User` to a `Product` accurately traversed through an intermediary `Order` table, generating the correct dual `LEFT JOIN` statements without resulting in infinite recursion or circular dependency loops.

## 4.4 Performance Evaluation: Subquery Pushdown
A critical evaluation metric for this project was its memory footprint when generating aggregations over large datasets.
- **Naive Approach (O(N) Memory)**: If the system had hydrated Eloquent models (e.g., fetching 10,000 `User` records and looping through them in PHP to count related `Orders`), the server's RAM usage would scale linearly (O(N)) with the dataset, eventually causing a fatal Out-Of-Memory error.
- **Optimized Approach (O(1) Memory)**: By implementing Virtual Attributes via **Subquery Pushdown**, the engine transpiles the aggregation into a raw SQL fragment (e.g., `SELECT COUNT(...)`). The database engine (MySQL/SQLite) performs the calculation in optimized C-code at the hardware level. Consequently, the PHP application only receives a single scalar value per row, maintaining a flat O(1) memory footprint regardless of whether the database processes 100 or 1,000,000 records.

## 4.5 User Acceptance Testing (Demo Environment)
The AlpineJS demonstration application successfully validated the API boundary. The complex, recursive mapping functions written in the frontend (`loadReportToEditor()`) successfully fetched the strict, nested AST from the database and flattened it into visual UI blocks, proving that the backend architecture is truly decoupled and capable of supporting headless operations.
