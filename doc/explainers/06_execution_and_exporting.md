# 06. Execution and Exporting

Once a strictly typed `ReportRequest` AST payload has been passed to the backend, the Dynamic Report Generator engine compiles the payload into a sophisticated, optimized SQL query using graph resolution and subquery pushdown.

However, executing that query securely and efficiently against a production database requires careful memory management. The engine provides three primary methods for executing the payload, abstracted via the `DynamicReport` facade.

---

## 1. Standard Generation (Development / Small Data)

The `generate()` method compiles the AST and returns a standard Laravel `Illuminate\Database\Query\Builder` instance. The engine automatically resolves Attribute Level Security (ALS) when you pass `$subjects` — an array of entities whose restriction rules should apply (e.g., the authenticated user and their roles).

**Code Example:**
```php
// Option A: Pass subjects for ALS enforcement
$subjects = auth()->user()->getDynamicReportSubjects();
$query = DynamicReport::generate($reportRequest, $subjects);

// Option B: Without subjects (no ALS filtering)
$query = DynamicReport::generate($reportRequest);

$results = $query->get();
```

> [!WARNING]
> **RAM Crash Risk**
> You should **never** call `->get()` without limits in a production environment if there is a chance the report will return millions of rows. Fetching millions of rows simultaneously will exceed PHP's memory limit (Out-Of-Memory error) and crash the backend server. The engine enforces a configurable `max_rows` safety limit (default: 5000) as a backstop, but explicit pagination is still recommended.

---

## 2. Paginated Generation (Production UI)

For presenting data back to a User Interface (like Vue, React, or Blade), you should always use pagination. The engine abstracts this via the `generatePaginated()` method, returning an `Illuminate\Contracts\Pagination\LengthAwarePaginator`.

**Code Example:**
```php
// Compiles the AST and automatically paginates at 50 rows per page
$subjects = auth()->user()->getDynamicReportSubjects();
$paginator = DynamicReport::generatePaginated($reportRequest, 50, $subjects);

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

To solve this, the engine natively provides an `exportToCsv()` method. It bypasses memory loading entirely by utilizing a database `cursor()` and injecting the rows directly into a Symfony `StreamedResponse`.

**Code Example:**
```php
public function downloadReport(Request $request) {
    $reportRequest = ReportRequest::fromJson($request->input('payload'));
    $subjects = auth()->user()->getDynamicReportSubjects();
    
    // Streams the CSV directly to the user's browser, one row at a time via cursor
    return DynamicReport::exportToCsv($reportRequest, 'sales_report_2026.csv', $subjects);
}
```

> [!IMPORTANT]
> **Zero-Memory Buffering**
> Because it uses a `StreamedResponse` backed by a database `cursor()`, the CSV file is never constructed in PHP memory. Each row is fetched one at a time via a server-side cursor and piped directly over the HTTP connection to the user's browser. This means you can safely export a 10GB CSV file from a 512MB RAM server.

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

---

## 5. Headless Execution (Saved Reports)

For executing a previously saved report without any UI involvement — such as in scheduled jobs or API integrations — the engine provides `loadAndGenerate()`. This fetches the saved AST from the database, deserializes it, executes it, and logs the action.

**Code Example:**
```php
// Execute saved report #42 and log it as run by user #1
$query = DynamicReport::loadAndGenerate(42, auth()->id());
$results = $query->get();
```

> [!TIP]
> **Alias Preservation**
> Column aliases defined when the report was originally saved are correctly preserved during deserialization via the `ReportSerializer`, ensuring that exported column headers match the original report design.
