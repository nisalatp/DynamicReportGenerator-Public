# Chapter 1: Introduction

## 1.1 Background
In modern enterprise software development, data accessibility remains a significant bottleneck. While relational databases efficiently store vast amounts of business data, extracting meaningful insights typically requires specialized knowledge of Structured Query Language (SQL). As businesses grow, the demand for custom reporting often overwhelms engineering teams, leading to a desire for self-service Business Intelligence (BI) tools.

## 1.2 Problem Statement
Existing reporting solutions tend to fall into two problematic extremes. On one hand, heavy Enterprise BI platforms (like Tableau or Metabase) require separate infrastructure, complex data-warehousing, and substantial licensing fees. On the other hand, native framework admin panels (like Laravel Nova) are too rigid, typically restricting users to basic CRUD (Create, Read, Update, Delete) operations on single tables without the ability to perform complex, multi-table joins or dynamic aggregations. 

Furthermore, exposing dynamic query generation directly to a user interface introduces severe security risks, notably SQL injection, if not handled through strict architectural boundaries.

## 1.3 Project Objectives
The primary objective of this capstone project is to bridge this gap by developing the **Dynamic Report Generator**—an installable, enterprise-grade Laravel package. The specific objectives include:
1. **Decoupled Architecture**: To build a reporting engine that operates entirely independently of the host application's UI, communicating strictly through Data Transfer Objects (DTOs).
2. **Abstract Syntax Tree (AST) Compilation**: To develop a compiler that safely transpiles JSON-based visual payloads into optimized database queries, eliminating SQL injection vectors.
3. **Automated Graph-Based Joins**: To implement a Breadth-First Search (BFS) algorithm utilizing PHP Reflection to automatically map and resolve Eloquent relationships without user intervention.
4. **Virtual Attribute Extensibility**: To create a Subquery Pushdown mechanism that allows administrators to define complex, cross-table calculated columns that execute at hardware speed on the RDBMS.

## 1.4 Scope and Limitations
The scope of this project encompasses the backend reporting engine, the persistence lifecycle (saving, sharing, and auditing reports), and a demonstration frontend environment (built with AlpineJS) to prove the viability of the API. 

The system is limited to relational database management systems (RDBMS) supported by Laravel's Eloquent ORM (MySQL, PostgreSQL, SQLite). It does not natively support NoSQL databases (e.g., MongoDB). Furthermore, the package delegates all HTTP authentication and session management to the host application to maintain framework agnosticism.
