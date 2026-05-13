# 06. Execution and Exporting

Once a strictly typed `ReportRequest` AST payload has been passed to the backend, the Dynamic Report Generator engine compiles the payload into a sophisticated, optimized SQL query using graph resolution and subquery pushdown.

However, executing that query securely and efficiently against a production database requires careful memory management. The engine provides three primary methods for executing the payload, abstracted via the `DynamicReport` facade.

---

## 1. Standard Generation (Development / Small Data)

The `generate()` method compiles the AST and returns a standard Laravel `Illuminate\Database\Query\Builder` instance. Before you execute the query, you should always enforce Attribute Level Security (ALS) using the `GovernanceManager` to ensure the currently authenticated user is authorized to see the requested fields.

**Code Example:**
```php
// Retrieve ALS rules for the current user's role
$matrix = GovernanceManager::getMatrix($reportRequest->baseModel, Role::class, $user->role_id);

// Generate the Query Builder and automatically inject masking logic based on the matrix
$query = DynamicReport::generate($reportRequest, $matrix['attributes']);
$results = $query->get();
```

> [!WARNING]
> **RAM Crash Risk**
> You should **never** call `->get()` without limits in a production environment if there is a chance the report will return millions of rows. Fetching millions of rows simultaneously will exceed PHP's memory limit (Out-Of-Memory error) and crash the backend server.

---

## 2. Paginated Generation (Production UI)

For presenting data back to a User Interface (like Vue, React, or Blade), you should always use pagination. The engine abstracts this via the `generatePaginated()` method, returning an `Illuminate\Contracts\Pagination\LengthAwarePaginator`.

**Code Example:**
```php
// Compiles the AST and automatically paginates at 50 rows per page
$paginator = DynamicReport::generatePaginated($reportRequest, 50);

return response()->json([
    'data' => $paginator->items(),
    'current_page' => $paginator->currentPage(),
    'last_page' => $paginator->lastPage(),
    'total' => $paginator->total()
]);
```

> [!TIP]
> **Memory Protection**
> This method guarantees that no matter how complex the report is, or how many millions of rows match the criteria, the PHP application will only ever load 50 rows into RAM at a time. This keeps the memory footprint at O(1) complexity.

---

## 3. CSV Streaming (Exporting)

The most requested feature for any reporting engine is the ability to export the data to Excel or CSV. Exporting millions of rows is inherently dangerous.

To solve this, the engine natively provides an `exportToCsv()` method. It bypasses memory loading entirely by utilizing database `chunk()` iteration and injecting the rows directly into a `StreamedResponse`.

**Code Example:**
```php
public function downloadReport(Request $request) {
    $reportRequest = ReportRequest::fromJson($request->input('payload'));
    
    // Streams the CSV directly to the user's browser, pulling 1000 rows at a time
    return DynamicReport::exportToCsv($reportRequest, 'sales_report_2026.csv');
}
```

> [!IMPORTANT]
> **Zero-Memory Buffering**
> Because it uses a `StreamedResponse`, the CSV file is never constructed in PHP memory. It streams bytes directly over the HTTP connection to the user's browser as the database chunking runs. This means you can safely export a 10GB CSV file from a 512MB RAM server.

---

## 4. Raw SQL Debugging (AI Context & Testing)

If you are building an AI Agent (via MCP) or debugging a complex visual builder, you often need to see the exact raw SQL that the engine compiled. Because Laravel separates the SQL string from the parameter bindings, this usually requires messy Regex manipulation.

The engine abstracts this away with the `toRawSql()` helper, which takes a generated Builder and returns the beautiful, fully-bound raw SQL string.

**Code Example:**
```php
$query = DynamicReport::generate($reportRequest);
$rawSqlString = DynamicReport::toRawSql($query);

// Returns: "SELECT t0.*, SUM(t1.amount) as total_revenue FROM users as t0 LEFT JOIN orders as t1..."
return response()->json(['sql' => $rawSqlString]);
```
