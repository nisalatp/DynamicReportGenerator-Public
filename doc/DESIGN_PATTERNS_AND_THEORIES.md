# Design Patterns and Computer Science Theories
## Dynamic Report Generator Architecture

This document explains the advanced Computer Science theories and Software Engineering Design Patterns utilized in the Dynamic Report Generator. It is written to be easily understandable, even for readers with no prior background in these concepts.

---

## 1. Compiler Theory

When you write code in PHP or Java, a **Compiler** translates your human-readable text into machine code (1s and 0s) that the computer understands. The Dynamic Report Generator acts exactly like a compiler, but instead of translating PHP to Machine Code, it translates a **JSON Payload into an SQL Query**.

### 1.1 The Abstract Syntax Tree (AST)
Compilers don't read text from top to bottom like a human reading a book. Instead, they parse the text into a tree-like data structure called an **Abstract Syntax Tree (AST)**.
- **Example**: If you give a math compiler the equation `2 + (3 * 4)`, it doesn't solve it left-to-right. It builds a tree where `+` is the root, `2` is the left branch, and `(3 * 4)` is the right branch. It evaluates the deepest branches first.
- **How We Use It**: Our engine forces the frontend (Vue, React, Blade) to send a strictly typed JSON object instead of a raw SQL string. This JSON object *is* an AST. The backend "compiles" this AST tree, walking through the branches (filters, columns, sorting) to generate the final `SELECT ... FROM ... WHERE` SQL statement.

---

## 2. Graph Theory (Bidirectional Breadth-First Search)

A database is just a collection of tables connected by foreign keys. In Mathematics, a collection of connected nodes is called a **Graph**. 

### 2.1 The Problem
Imagine you want a report showing the "User's Name" and the "Product Name they bought". These exist in two completely different tables (`users` and `products`). To get the data, you must perform an SQL `JOIN` through an intermediate table (`orders`). For a non-technical user, figuring out how to connect these tables is impossible.

A further complication arises when relationships are only declared in one direction. For example, an `OrderItem` might declare "I belong to a Product" (`BelongsTo`), but the `Product` model might not declare "I have many OrderItems" (`HasMany`). A naive engine would only see the connection from OrderItem to Product, not the reverse — making it unable to find a path from Product to OrderItem.

### 2.2 Breadth-First Search (BFS) Algorithm
**Breadth-First Search** is a famous graph traversal algorithm. It is used in GPS systems to find the shortest path between two cities. 
- **Example**: If you want to drive from New York to Los Angeles, a BFS algorithm explores all neighboring cities layer by layer until it finds the shortest, most direct route to LA without driving in circles.
- **How We Use It**: When the user requests `users` and `products`, the engine uses BFS to explore the database relationships. It automatically discovers that the shortest path is `users → orders → products`. It then automatically writes the complex `JOIN` SQL logic on the user's behalf.

### 2.3 Bidirectional Graph Construction
To ensure BFS can always find a path regardless of which direction a developer declared their relationships, the engine performs **two-phase link discovery**:
1. **Forward Discovery**: Scans each model's public methods via PHP Reflection to find explicitly declared Eloquent relationships (the edges that developers wrote).
2. **Reverse Synthesis**: For every forward edge A→B where the inverse B→A does not exist, the engine automatically creates the reverse edge by inverting the relationship type (e.g., `BelongsTo` becomes `HasMany`).

This produces a fully **bidirectional** graph where BFS can traverse in either direction, solving the one-directional declaration problem. The graph is cached in-memory to avoid repeating the expensive reflection process.

---

## 3. Design Patterns

Design patterns are proven, standardized solutions to common problems in software design.

### 3.1 Data Transfer Object (DTO) Pattern
- **The Concept**: Passing raw arrays of data between functions is dangerous because you never know exactly what the array contains. A DTO is a strict, rigid container for data.
- **Example**: Instead of passing an array `['name' => 'John', 'age' => 30]`, you create a `PersonDTO` class that *requires* a string name and an integer age.
- **How We Use It**: The incoming JSON payload is immediately converted into strict DTOs (`ReportRequest`, `Aggregate`, `Sort`). The `Aggregate` DTO, for example, has a strict whitelist that only accepts `SUM`, `COUNT`, `AVG`, `MAX`, `MIN`. If a hacker tries to send `DROP TABLE`, the DTO strictly rejects it, preventing SQL injection. Similarly, the `FilterLeaf` DTO validates operators and the `Sort` DTO restricts directions to `ASC`/`DESC`.

### 3.2 Composite Pattern
- **The Concept**: The Composite pattern allows you to treat individual objects and groups of objects in the exact same way by placing them in a tree structure.
- **Example**: Think of a computer file system. A Folder can contain Files, but a Folder can also contain *other* Folders. Whether you are deleting a single File or a Folder containing 100 nested Folders, the operating system handles the "delete" command gracefully.
- **How We Use It**: We use this for SQL `WHERE` clauses. A `FilterLeaf` is a single rule (e.g., `age > 18`). A `FilterGroup` is a folder that holds multiple rules using `AND` / `OR` logic. Because of the Composite Pattern, our engine can parse infinitely nested, massively complex SQL filter logic using a simple recursive function. The maximum nesting depth is configurable via `max_filter_depth` (default: 3) and enforced both server-side and in the frontend UI.

### 3.3 Singleton Pattern
- **The Concept**: The Singleton pattern ensures that a class only ever has **one** instance (one object) created in memory during the entire lifecycle of the application.
- **Example**: Think of a country's President. There can only be one President at a time. If different government departments need to ask the President a question, they all talk to the exact same person, rather than cloning a new President each time.
- **How We Use It**: Our `VirtualAttributeRegistry` is a Singleton. Virtual Attributes (like "Total Spent") are stored in the database. Instead of querying the database 50 times when compiling a massive report, the Singleton fetches all the attributes exactly *once* when the application boots, and holds them in memory. This drastically improves performance.

### 3.4 Facade Pattern
- **The Concept**: A Facade hides an incredibly complex system behind a very simple, clean interface.
- **Example**: Think of a car's steering wheel. The steering column connects to a complex hydraulic power steering pump, rack, and pinion gears. But the driver doesn't need to understand hydraulics; they just turn the simple wheel left or right. The wheel is a Facade.
- **How We Use It**: The `ReportMaker` engine is highly complex, requiring dependency injection, model analysis, and graph mapping. We hide all of this behind the `DynamicReport` Facade. A developer simply types `DynamicReport::generate($payload)`, and the Facade handles the massive complexity in the background.

### 3.5 Broker Pattern
- **The Concept**: A Broker acts as a middleman that orchestrates communication between decoupled systems that don't know how to talk to each other directly.
- **Example**: A stockbroker. You want to buy Apple stock, but you don't know who is currently selling it. You tell your broker, and the broker finds the seller and executes the trade for you.
- **How We Use It**: A Vue.js frontend cannot physically read the backend PHP Database Schema. The `ReportMaker` engine acts as a Schema Discovery Broker. The frontend asks the engine, "What columns are available for the User model?" The engine (the broker) inspects the database, merges it with Virtual Attributes, and returns a clean, unified JSON list to the frontend. It also exposes configuration values like `getMaxFilterDepth()` so the frontend can enforce consistent rules.

### 3.6 Builder Pattern (Fluent Interface)
- **The Concept**: The Builder pattern constructs a complex object step by step through a sequence of method calls, instead of requiring all parameters at once in a massive constructor.
- **Example**: Think of ordering a custom sandwich at a deli counter. Instead of describing your entire sandwich in one sentence, you tell the worker step by step: "Start with whole wheat... add turkey... add lettuce... add mustard." Each step builds on the previous one.
- **How We Use It**: The `ReportBuilder` and `FilterBuilder` allow developers to construct complex report configurations programmatically using fluent method chaining:
  ```php
  $request = ReportBuilder::forModel(User::class)
      ->select(User::class, 'name', 'string')
      ->aggregate(Order::class, 'total', 'SUM', 'decimal', 'total_orders')
      ->filter(fn($f) => $f->where(User::class, 'active', '=', true, 'boolean'))
      ->build();
  ```
  This pattern is also used in `VirtualAttributeBuilder` to register Virtual Attributes via a declarative, step-by-step API.
