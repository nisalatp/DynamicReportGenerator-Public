# Dynamic Report Generator for Laravel

![Laravel](https://img.shields.io/badge/Laravel-11.x_|_12.x_|_13.x-FF2D20?style=for-the-badge&logo=laravel)
![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php)
![License](https://img.shields.io/badge/License-MIT-blue?style=for-the-badge)
[![Packagist Version](https://img.shields.io/packagist/v/nisalatp/dynamicreportgenerator?style=for-the-badge)](https://packagist.org/packages/nisalatp/dynamicreportgenerator)
![Downloads](https://img.shields.io/packagist/dt/nisalatp/dynamicreportgenerator?style=for-the-badge)

Hi there! 👋 Welcome to the **Dynamic Report Generator** — a reporting engine that lets your **non-technical users build their own complex, multi-table reports**, safely, without you writing a single query for them.

If you're a Laravel developer, you've been here: you ship a beautiful dashboard, and immediately get hit with *"can we add a column for the user's last order date? what about filtering by customers who spent over $500 but haven't logged in recently?"* Before long you're writing endless `LEFT JOIN`s and a new controller for every report request. It's a bottleneck. This package removes it.

> 📦 **Live on Packagist** &nbsp;·&nbsp; 📚 **[Hosted documentation](https://nisalatp.github.io/DynamicReportGenerator-Public/)** &nbsp;·&nbsp; ✅ Independently built & validated by a third-party developer using only the published package and its docs.

---

## ✨ Why You'll Love It

- **No more manual joins.** A Graph-Theory **Breadth-First Search** reads your Eloquent relationships and finds the *shortest* join path between models automatically. You declare *what* you want; the engine works out *how* to join it.
- **Virtual Attributes (the secret sauce).** Pre-register a heavy SQL expression — say, a user's lifetime value — as a named "Virtual Attribute". To your end-users it's just another column to tick.
- **Memory-efficient by design.** The engine compiles your request and pushes the work *down to the database*; it does **not** hydrate thousands of Eloquent models into PHP just to aggregate them. Virtual Attributes run as correlated subqueries — O(1) application memory, whatever the row count.
- **Predictable.** Call `explainJoinPlan()` to inspect the resolved JOIN path, or `toRawSql()` to see the exact compiled query — *before* you run it.
- **Secure by default.** Built-in **attribute-level security** — mask or block any column per user or role — alongside report assignment and full audit logging.
- **100% UI-agnostic.** Vue, React, Livewire, AlpineJS, a Java client — even an LLM via the Model Context Protocol. They all speak the same JSON AST.

---

## 📦 Installation

```bash
composer require nisalatp/dynamicreportgenerator
```

Requires **PHP 8.2+** and **Laravel 11, 12 or 13**. The Service Provider and the `DynamicReport` facade are auto-discovered — no manual registration needed.

```bash
php artisan migrate    # creates the dynamic_* tables (saved reports, logs, virtual attributes)
```

Then list the models you want to expose for reporting in `config/dynamicreportgenerator.php` under `reportable_models`. See the [docs](https://nisalatp.github.io/DynamicReportGenerator-Public/) for the full setup.

---

## 🧭 Architecture: a deterministic **Build → Save → Run** lifecycle

The engine deliberately separates *what a report is* (configuration) from *running it* (execution). A report definition is a strictly-typed **Abstract Syntax Tree (AST)** — never raw SQL — so the same definition always compiles to the same parameterised query. That separation is what lets you save, share and re-run reports predictably at scale.

![High-level architecture](https://raw.githubusercontent.com/nisalatp/DynamicReportGenerator-Public/main/docs/assets/architecture.png)

| Phase | What happens | Key API |
|-------|--------------|---------|
| **Build** | A frontend (or the fluent `ReportBuilder`) produces a typed AST: base model, join targets, columns, filters, group-bys, aggregates. No SQL. | `ReportBuilder`, `ReportRequest` |
| **Save** | The AST is persisted as a JSON payload (DB-agnostic — MySQL, PostgreSQL or SQLite), can be assigned to users, and every execution is audit-logged. | `saveReport()`, `assignReport()`, `getReportLogs()` |
| **Run** | The engine compiles the AST: BFS resolves the joins, Virtual Attributes are injected as subqueries, values are PDO-bound, and you get an Eloquent query back. | `generate()`, `generatePaginated()`, `loadAndGenerate()`, `exportToCsv()` |

---

## 🚀 Quick Start

### 1. Build and run a report

```php
use App\Models\{User, Order};
use Nisalatp\DynamicReportGenerator\Builders\ReportBuilder;
use Nisalatp\DynamicReportGenerator\Facades\DynamicReport;

$request = ReportBuilder::forModel(User::class)
    ->withTarget(Order::class)                  // BFS finds & builds the User→Order join for you
    ->select(User::class, 'name')
    ->select(Order::class, 'status')
    ->filter(fn ($f) => $f->where(Order::class, 'status', 'completed'))
    ->build();

return DynamicReport::generate($request)->paginate(50);
```

No `JOIN`, no `DB::raw`, no per-report controller.

### 2. See exactly what it will do — before you run it

```php
$plan = DynamicReport::explainJoinPlan($request);                    // the resolved JOIN path
$sql  = DynamicReport::toRawSql(DynamicReport::generate($request));  // the compiled SQL
```

### 3. Save a definition, then re-run it later

```php
$saved = DynamicReport::saveReport('Completed orders by user', $request, userId: auth()->id());

// elsewhere — load the saved configuration and execute it (writes an audit-log entry)
return DynamicReport::loadAndGenerate($saved->id, executedByUserId: auth()->id())->paginate(50);
```

### 4. Virtual Attributes — expose heavy SQL as a simple column

Register a Virtual Attribute once (in a Service Provider or an admin screen); users then select it like any other column.

```php
use Nisalatp\DynamicReportGenerator\Builders\VirtualAttributeBuilder;

VirtualAttributeBuilder::create('Lifetime Value')
    ->forBaseModel(User::class)
    ->dependsOn([Order::class])
    ->withSqlFragment('(SELECT COALESCE(SUM(amount), 0) FROM orders WHERE orders.user_id = t0.id)')
    ->register();
```

> ⚠️ **Alias the base table as `t0`.** The engine compiles the base model as `... as t0`, so a correlated-subquery fragment must reference `t0` (e.g. `orders.user_id = t0.id`) — not the literal table name.

Then your users just select it like a normal column:

```php
->select(User::class, 'Lifetime Value', 'integer', isVirtual: true)
```

---

## 🔐 Attribute-Level Security (ALS)

Beyond report assignment, the engine ships with a **column-level security firewall**. Restrict any attribute for a *subject* (a user, role, team, …) in one of two modes:

- **`masked`** — the column may still be selected, but its values are redacted in the output.
- **`blocked`** — the column is off-limits; using it in a filter, grouping, aggregate, or sort raises a `ReportMakerSecurityException` (`blocked` always wins over `masked`).

```php
use App\Models\{User, Role};
use Nisalatp\DynamicReportGenerator\Facades\DynamicReport;

DynamicReport::restrictAttribute(User::class, 'salary', $supportRole, 'masked');  // redact
DynamicReport::restrictAttribute(User::class, 'ssn',    $supportRole, 'blocked'); // forbid
```

Enforcement is automatic at generation time. Pass the subjects explicitly, or let the engine resolve them from the authenticated user:

```php
// explicit subjects
$rows = DynamicReport::generate($request, [$supportRole])->paginate(50);

// implicit — if your User implements DynamicReportSubject, the engine calls
// $user->getDynamicReportSubjects() for the logged-in user automatically
$rows = DynamicReport::generate($request)->paginate(50);
```

There's also **model-level** restriction — `restrictModel(Model::class)` hides an entire model from the report surface. Per-framework walkthroughs live in the [Attribute-Level Security examples](https://nisalatp.github.io/DynamicReportGenerator-Public/#/examples/blade/attribute-level-security).

---

## 🖼️ See it in action

The bundled point-and-click builder: pick a base model, add join targets and columns, and watch the **Compiled SQL** and the **live JSON AST** update as you build — the user never writes SQL or a join.

![The report builder](https://raw.githubusercontent.com/nisalatp/DynamicReportGenerator-Public/main/docs/assets/playground.png)

Run it for paginated results, then **export to CSV**:

![Query results and CSV export](https://raw.githubusercontent.com/nisalatp/DynamicReportGenerator-Public/main/docs/assets/results.png)

---

## 🔒 Security by design

- Input is a **typed JSON AST**, validated at the boundary — raw user SQL never reaches the compiler.
- All filter values are transmitted as **PDO parameter bindings**.
- Aggregate functions are checked against an **immutable whitelist** (`SUM`, `AVG`, `COUNT`, `MIN`, `MAX`).

An independent review judged the engine *"inherently secure against SQL injection."*

---

## 🌐 Frontend-agnostic & AI-ready

One JSON AST drives every client — Blade/AlpineJS, Vue, React, a Java service. Because it's plain JSON, the AST is also natively compatible with the **Model Context Protocol (MCP)**, so an LLM can turn a natural-language question straight into a valid report request. Runnable examples for each ecosystem live in the **[hosted docs](https://nisalatp.github.io/DynamicReportGenerator-Public/)**, alongside the full [AST reference](https://nisalatp.github.io/DynamicReportGenerator-Public/#/reference/ast-reference).

---

## ✅ Tested & validated

Covered by a **PHPUnit 11 + Orchestra Testbench** suite (76 tests, in-memory SQLite) spanning BFS join resolution, AST compilation, Virtual Attributes, SQL-injection binding and persistence. Independently, a third-party developer built a complete Laravel application from *only* the published package and the hosted docs, and reported it *"a robust, production-ready reporting engine."*

---

## 🤝 Contributing

This project is free and open-source. Issues and pull requests are welcome — whether that's NoSQL exploration, optimising the graph traversal, new frontend examples, or fixing a typo. Fork it, make your change, and open a PR.

## 📄 License

Open-sourced under the [MIT license](https://opensource.org/licenses/MIT). Built by **Nisala Aloka Bandara**.
