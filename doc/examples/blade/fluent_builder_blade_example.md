# Fluent Builder API Example (PHP Backend)

This example demonstrates the PHP-only Fluent Builder API — `ReportBuilder`, `FilterBuilder`, and `VirtualAttributeBuilder`. These are used server-side (in controllers, seeders, or scheduled tasks) to programmatically construct reports without raw JSON.

> **Note**: The Fluent Builder is a backend-only API. Frontend examples (React, Vue, Java, MCP) construct AST payloads as JSON and send them to the REST API. This example shows the equivalent PHP-native approach.

---

## 1. ReportBuilder — Constructing a Report Programmatically

```php
use Nisalatp\DynamicReportGenerator\Builders\ReportBuilder;
use Nisalatp\DynamicReportGenerator\Facades\DynamicReport;

// Build a report: Total revenue by country for active users
$request = ReportBuilder::forModel('App\Models\User')
    ->withTarget('App\Models\Order')
    ->select('App\Models\User', 'name', 'string')
    ->select('App\Models\User', 'country', 'string')
    ->groupBy('App\Models\User', 'country', 'string')
    ->aggregate('App\Models\Order', 'amount', 'integer', 'SUM', 'total_revenue')
    ->aggregate('App\Models\Order', 'id', 'integer', 'COUNT', 'order_count')
    ->filter(function ($filter) {
        $filter->where('App\Models\User', 'status', '=', 'active', 'string');
    })
    ->having(function ($filter) {
        $filter->where('App\Models\Order', 'amount', '>', 10000, 'integer', true);
    })
    ->build();

// Execute with the engine
$query = DynamicReport::generate($request);
$results = $query->get();
```

---

## 2. FilterBuilder — Complex Nested Filters

```php
use Nisalatp\DynamicReportGenerator\Builders\ReportBuilder;

$request = ReportBuilder::forModel('App\Models\User')
    ->withTarget('App\Models\Order')
    ->withTarget('App\Models\Product')
    ->select('App\Models\User', 'name', 'string')
    ->select('App\Models\Product', 'category', 'string')
    ->filter(function ($filter) {
        // WHERE status = 'active'
        $filter->where('App\Models\User', 'status', '=', 'active', 'string');
        
        // AND (category = 'Electronics' OR category = 'Software')
        $filter->nested(function ($sub) {
            $sub->where('App\Models\Product', 'category', '=', 'Electronics', 'string');
            $sub->orWhere('App\Models\Product', 'category', '=', 'Software', 'string');
        });
        
        // AND email IS NOT NULL
        $filter->whereNotNull('App\Models\User', 'email', 'string');
        
        // AND price BETWEEN [100, 500]
        $filter->whereBetween('App\Models\Product', 'price', [100, 500], 'integer');
        
        // AND country IN ['US', 'UK', 'DE']
        $filter->whereIn('App\Models\User', 'country', ['US', 'UK', 'DE'], 'string');
    })
    ->build();
```

### Available FilterBuilder Methods

| Method | SQL Equivalent | Example |
|--------|---------------|---------|
| `->where(model, col, op, val)` | `WHERE col op val` | `->where('User', 'age', '>', 18)` |
| `->orWhere(model, col, op, val)` | `OR col op val` | `->orWhere('User', 'role', '=', 'admin')` |
| `->whereNull(model, col)` | `WHERE col IS NULL` | `->whereNull('User', 'deleted_at')` |
| `->whereNotNull(model, col)` | `WHERE col IS NOT NULL` | `->whereNotNull('User', 'email')` |
| `->whereIn(model, col, values)` | `WHERE col IN (...)` | `->whereIn('User', 'country', ['US','UK'])` |
| `->whereBetween(model, col, [a,b])` | `WHERE col BETWEEN a AND b` | `->whereBetween('Order', 'amount', [100,500])` |
| `->nested(closure, logic)` | `AND (...)` | `->nested(fn($f) => ..., 'and')` |
| `->orNested(closure)` | `OR (...)` | `->orNested(fn($f) => ...)` |

---

## 3. VirtualAttributeBuilder — Registering VAs Programmatically

```php
use Nisalatp\DynamicReportGenerator\Builders\VirtualAttributeBuilder;

// Register a "total_revenue" VA on the User model
$va = VirtualAttributeBuilder::create('total_revenue')
    ->forBaseModel('App\Models\User')
    ->withReturnType('integer')
    ->withSqlFragment('(SELECT SUM(orders.amount) FROM orders WHERE orders.user_id = {THIS}.id)')
    ->dependsOn(['App\Models\Order'])
    ->register();

// Uses updateOrCreate internally — safe to run multiple times (idempotent)
echo $va->id; // The persisted VirtualAttribute model ID
```

---

## 4. Full Workflow: Build, Save, Execute

```php
use Nisalatp\DynamicReportGenerator\Builders\ReportBuilder;
use Nisalatp\DynamicReportGenerator\Facades\DynamicReport;

// Step 1: Build the report definition
$request = ReportBuilder::forModel('App\Models\User')
    ->withTarget('App\Models\Order')
    ->select('App\Models\User', 'country', 'string')
    ->groupBy('App\Models\User', 'country', 'string')
    ->aggregate('App\Models\Order', 'amount', 'integer', 'SUM', 'total_revenue')
    ->build();

// Step 2: Save it
$saved = DynamicReport::saveReport('Revenue by Country', $request, auth()->id(), 'Q2 analysis');

// Step 3: Execute later (e.g., from a scheduled job)
$query = DynamicReport::loadAndGenerate($saved->id, auth()->id());
$results = $query->get();

// Step 4: Export to CSV
$csvResponse = DynamicReport::exportToCsv($request, 'revenue.csv');

// Step 5: Debug the SQL
$rawSql = DynamicReport::toRawSql(DynamicReport::generate($request));

// Step 6: Inspect the join plan
$joinPlan = DynamicReport::explainJoinPlan($request);
foreach ($joinPlan->steps as $step) {
    echo "{$step->fromModel} → {$step->toModel} ({$step->relationType}, {$step->direction})\n";
}
```

> [!TIP]
> The Fluent Builder produces the exact same `ReportRequest` DTO that `ReportBuilderRequest::fromPayload()` creates from frontend JSON. Both paths converge at `DynamicReport::generate()`.
