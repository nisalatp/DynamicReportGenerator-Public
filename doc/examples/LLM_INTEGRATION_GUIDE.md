# Dynamic Report Generator — AI Agent Integration Guide (Complete Reference)

> **FOR AI CODING AGENTS / LLMs ONLY**
> This file is a machine-readable instruction set. When pointed at this file, the `/doc/examples/` folder, and the package source in `/src/`, you will have everything needed to fully integrate the Dynamic Report Generator into any Laravel application.

---

## CRITICAL CONTEXT

- **Package**: `nisalatp/dynamic-report-generator`
- **Namespace**: `Nisalatp\DynamicReportGenerator`
- **Compatibility**: Laravel 10, 11, 12
- **Facade**: `Nisalatp\DynamicReportGenerator\Facades\DynamicReport`
- **Route Prefix**: `ntp_drg`
- **Architecture**: Headless engine. Consumes strict JSON AST payloads. No SQL strings accepted. All joins resolved automatically via bidirectional BFS graph traversal.

---

## PHASE 1: INSTALLATION

```bash
# TODO: Package will be published to Packagist. Use this command:
composer require nisalatp/dynamic-report-generator

# Until then, add as a path repository in composer.json:
# "repositories": [{ "type": "path", "url": "../path-to-package" }]
```

After install, publish config and run migrations:

```bash
php artisan vendor:publish --tag=dynamicreportgenerator-config
php artisan migrate
```

This creates 7 tables: `dynamic_saved_reports`, `dynamic_report_logs`, `dynamic_report_user` (pivot), `dynamic_virtual_attributes`, `dynamic_restricted_models`, `dynamic_attribute_restrictions`.

---

## PHASE 2: CONFIGURATION

Edit `config/dynamicreportgenerator.php`:

```php
return [
    // Whitelist models (empty = auto-discover all Eloquent models)
    'reportable_models' => [
        // App\Models\User::class,
        // App\Models\Order::class,
    ],
    'include_package_models' => false,
    'limits' => ['max_rows' => 5000],
    'http' => [
        'enabled' => false,
        'prefix' => 'ntp_drg',
        'middleware' => ['web', 'auth'],
    ],
    'ui' => ['max_filter_depth' => 3],
];
```

---

## PHASE 3: AUTHENTICATION HOOKUP

### Step 1: Check if auth exists

Look for `App\Models\User`. If it exists and extends `Authenticatable`, proceed to Step 2. If not, scaffold auth first:

```bash
composer require laravel/breeze --dev
php artisan breeze:install blade
npm install && npm run build
php artisan migrate
```

### Step 2: Implement the DynamicReportSubject contract

```php
// app/Models/User.php
use Nisalatp\DynamicReportGenerator\Contracts\DynamicReportSubject;

class User extends Authenticatable implements DynamicReportSubject
{
    public function getDynamicReportSubjects(): array
    {
        // Return the user itself for ALS resolution.
        // If you have roles: return array_merge([$this], $this->roles->all());
        return [$this];
    }
}
```

---

## PHASE 4: CREATE THE CONTROLLER

Create `app/Http/Controllers/DynamicReportController.php` with ALL routes:

```php
<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Nisalatp\DynamicReportGenerator\Facades\DynamicReport;
use Nisalatp\DynamicReportGenerator\Http\Requests\ReportBuilderRequest;
use Nisalatp\DynamicReportGenerator\Services\GovernanceManager;
use Nisalatp\DynamicReportGenerator\Services\VirtualAttributeManager;
use Nisalatp\DynamicReportGenerator\Services\VirtualAttributeCompiler;
use Nisalatp\DynamicReportGenerator\Builders\VirtualAttributeBuilder;

class DynamicReportController extends Controller
{
    // ─── SCHEMA DISCOVERY ───
    public function models()
    {
        return response()->json(DynamicReport::getAvailableModels());
    }

    public function attributes(Request $request)
    {
        $model = $request->query('model');
        return response()->json([
            'attributes' => DynamicReport::getModelAttributes($model),
            'relationships' => DynamicReport::getModelRelationships($model),
        ]);
    }

    public function config()
    {
        return response()->json([
            'max_filter_depth' => DynamicReport::getMaxFilterDepth(),
            'max_rows' => config('dynamicreportgenerator.limits.max_rows'),
        ]);
    }

    // ─── REPORT GENERATION ───
    public function generate(Request $request)
    {
        $reportRequest = ReportBuilderRequest::fromPayload($request->all());
        $subjects = auth()->user()?->getDynamicReportSubjects();
        $query = DynamicReport::generate($reportRequest, $subjects);
        return response()->json($query->get());
    }

    public function paginate(Request $request)
    {
        $reportRequest = ReportBuilderRequest::fromPayload($request->all());
        $subjects = auth()->user()?->getDynamicReportSubjects();
        return response()->json(
            DynamicReport::generatePaginated($reportRequest, $request->integer('per_page', 50), $subjects)
        );
    }

    public function exportCsv(Request $request)
    {
        $reportRequest = ReportBuilderRequest::fromPayload($request->all());
        $subjects = auth()->user()?->getDynamicReportSubjects();
        return DynamicReport::exportToCsv($reportRequest, 'report.csv', $subjects);
    }

    public function debugSql(Request $request)
    {
        $reportRequest = ReportBuilderRequest::fromPayload($request->all());
        $subjects = auth()->user()?->getDynamicReportSubjects();
        $query = DynamicReport::generate($reportRequest, $subjects);
        return response()->json([
            'sql' => DynamicReport::toRawSql($query),
            'join_plan' => DynamicReport::explainJoinPlan($reportRequest),
            'columns' => DynamicReport::getGeneratedColumns($reportRequest),
        ]);
    }

    // ─── REPORT PERSISTENCE ───
    public function saveReport(Request $request)
    {
        $reportRequest = ReportBuilderRequest::fromPayload($request->input('payload'));
        $saved = DynamicReport::saveReport(
            $request->input('name'), $reportRequest, auth()->id(), $request->input('description', '')
        );
        return response()->json($saved);
    }

    public function listReports()
    {
        return response()->json(DynamicReport::getSavedReports());
    }

    public function loadReport(int $id)
    {
        return response()->json(DynamicReport::loadToEditor($id));
    }

    public function runSavedReport(int $id)
    {
        $query = DynamicReport::loadAndGenerate($id, auth()->id());
        return response()->json($query->get());
    }

    public function updateReport(Request $request, int $id)
    {
        $reportRequest = ReportBuilderRequest::fromPayload($request->input('payload'));
        $saved = DynamicReport::updateReport(
            $id, $request->input('name'), $reportRequest, $request->input('description', ''), auth()->id()
        );
        return response()->json($saved);
    }

    public function deleteReport(int $id)
    {
        DynamicReport::deleteReport($id, auth()->id());
        return response()->json(['message' => 'Deleted']);
    }

    // ─── REPORT ACCESS CONTROL ───
    public function assignReport(Request $request, int $id)
    {
        DynamicReport::assignReport($id, $request->input('user_id'), auth()->id());
        return response()->json(['message' => 'Assigned']);
    }

    public function unassignReport(Request $request, int $id)
    {
        DynamicReport::unassignReport($id, $request->input('user_id'), auth()->id());
        return response()->json(['message' => 'Unassigned']);
    }

    public function assignedReports()
    {
        return response()->json(DynamicReport::getAssignedReports(auth()->id()));
    }

    public function reportLogs(int $id)
    {
        return response()->json(DynamicReport::getReportLogs($id));
    }

    // ─── MODEL-LEVEL SECURITY ───
    public function restrictModel(Request $request)
    {
        DynamicReport::restrictModel($request->input('model'), auth()->id());
        return response()->json(['message' => 'Model restricted']);
    }

    public function unrestrictModel(Request $request)
    {
        DynamicReport::unrestrictModel($request->input('model'));
        return response()->json(['message' => 'Model unrestricted']);
    }

    public function restrictedModels()
    {
        return response()->json(DynamicReport::getRestrictedModels());
    }

    // ─── ATTRIBUTE-LEVEL SECURITY (ALS) ───
    public function getGovernanceMatrix(Request $request)
    {
        return response()->json(GovernanceManager::getMatrix(
            $request->input('model'), $request->input('subject_type'), $request->integer('subject_id')
        ));
    }

    public function saveGovernanceMatrix(Request $request)
    {
        GovernanceManager::saveMatrix(
            $request->input('model'), $request->input('subject_type'),
            $request->integer('subject_id'), $request->boolean('is_reportable'),
            $request->input('attributes', []), auth()->id()
        );
        return response()->json(['message' => 'Matrix saved']);
    }

    // ─── VIRTUAL ATTRIBUTES ───
    public function registerVA(Request $request)
    {
        $va = VirtualAttributeBuilder::create($request->input('name'))
            ->forBaseModel($request->input('base_model'))
            ->withReturnType($request->input('return_type', 'string'))
            ->withSqlFragment($request->input('sql_fragment'))
            ->dependsOn($request->input('dependencies', []))
            ->register();
        return response()->json($va);
    }

    public function compileVisualVA(Request $request)
    {
        $sql = VirtualAttributeCompiler::compileVisualPayload($request->all());
        return response()->json(['sql_fragment' => $sql]);
    }

    public function listVAs()
    {
        return response()->json(VirtualAttributeManager::getAllWithUsageCounts());
    }

    public function deleteVA(Request $request, int $id)
    {
        VirtualAttributeManager::safeDelete($id, $request->boolean('force', false));
        return response()->json(['message' => 'Deleted']);
    }
}
```

> **See Part 2 for the routes file and AST payload reference.**
> **See Part 3 for Frontend integration, MCP setup, and the complete Feature Tree.**


## PHASE 5: REGISTER ROUTES

Add to `routes/api.php` (or `routes/web.php` if using web middleware):

```php
use App\Http\Controllers\DynamicReportController;

Route::prefix('ntp_drg')->middleware(['auth'])->group(function () {
    // Schema Discovery
    Route::get('/models', [DynamicReportController::class, 'models']);
    Route::get('/schema', [DynamicReportController::class, 'attributes']);
    Route::get('/config', [DynamicReportController::class, 'config']);

    // Report Generation
    Route::post('/generate', [DynamicReportController::class, 'generate']);
    Route::post('/paginate', [DynamicReportController::class, 'paginate']);
    Route::post('/export-csv', [DynamicReportController::class, 'exportCsv']);
    Route::post('/debug-sql', [DynamicReportController::class, 'debugSql']);

    // Report Persistence
    Route::get('/reports', [DynamicReportController::class, 'listReports']);
    Route::post('/reports', [DynamicReportController::class, 'saveReport']);
    Route::get('/reports/{id}/load', [DynamicReportController::class, 'loadReport']);
    Route::get('/reports/{id}/run', [DynamicReportController::class, 'runSavedReport']);
    Route::put('/reports/{id}', [DynamicReportController::class, 'updateReport']);
    Route::delete('/reports/{id}', [DynamicReportController::class, 'deleteReport']);

    // Report Access Control
    Route::post('/reports/{id}/assign', [DynamicReportController::class, 'assignReport']);
    Route::post('/reports/{id}/unassign', [DynamicReportController::class, 'unassignReport']);
    Route::get('/my-reports', [DynamicReportController::class, 'assignedReports']);
    Route::get('/reports/{id}/logs', [DynamicReportController::class, 'reportLogs']);

    // Model-Level Security
    Route::get('/security/restricted-models', [DynamicReportController::class, 'restrictedModels']);
    Route::post('/security/restrict-model', [DynamicReportController::class, 'restrictModel']);
    Route::post('/security/unrestrict-model', [DynamicReportController::class, 'unrestrictModel']);

    // Attribute-Level Security (ALS / Governance)
    Route::get('/governance/matrix', [DynamicReportController::class, 'getGovernanceMatrix']);
    Route::post('/governance/matrix', [DynamicReportController::class, 'saveGovernanceMatrix']);

    // Virtual Attributes
    Route::get('/virtual-attributes', [DynamicReportController::class, 'listVAs']);
    Route::post('/virtual-attributes', [DynamicReportController::class, 'registerVA']);
    Route::post('/virtual-attributes/compile', [DynamicReportController::class, 'compileVisualVA']);
    Route::delete('/virtual-attributes/{id}', [DynamicReportController::class, 'deleteVA']);
});
```

---

## PHASE 6: THE AST PAYLOAD FORMAT

Every report generation request (`POST /ntp_drg/generate`) must send a JSON body matching this exact schema. **No raw SQL is accepted.**

### Root Object: ReportRequest

```json
{
  "baseModel": "App\\Models\\User",
  "targetModels": ["App\\Models\\Order"],
  "selectedAttributes": [
    { "model": "App\\Models\\User", "column": "name", "type": "string" },
    { "model": "App\\Models\\Order", "column": "amount", "type": "integer" }
  ],
  "innerFilters": {
    "type": "group",
    "logic": "and",
    "children": [
      {
        "type": "leaf",
        "model": "App\\Models\\User",
        "column": "status",
        "dataType": "string",
        "operator": "=",
        "value": "active"
      }
    ]
  },
  "groupBys": [
    { "model": "App\\Models\\User", "column": "country", "type": "string" }
  ],
  "aggregates": [
    {
      "model": "App\\Models\\Order",
      "column": "amount",
      "type": "integer",
      "function": "SUM",
      "alias": "total_revenue"
    }
  ],
  "outerFilters": null,
  "sorts": [
    { "model": "App\\Models\\User", "column": "name", "type": "string", "direction": "ASC" }
  ]
}
```

### Field Rules

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `baseModel` | string (FQCN) | **Yes** | Root model for the query |
| `targetModels` | string[] | No | Models to JOIN. BFS resolves paths automatically |
| `selectedAttributes` | object[] | **Yes** | Each needs `model`, `column`, `type` |
| `innerFilters` | FilterNode | No | WHERE clause. Must use `type: "group"` or `type: "leaf"` |
| `groupBys` | object[] | No | GROUP BY columns |
| `aggregates` | object[] | No | SUM/COUNT/AVG/MAX/MIN with `function` and optional `alias` |
| `outerFilters` | FilterNode | No | HAVING clause (same format as innerFilters) |
| `sorts` | object[] | No | ORDER BY with `direction: "ASC"` or `"DESC"` |

### Allowed Filter Operators
`=`, `!=`, `>`, `>=`, `<`, `<=`, `like`, `in`, `between`, `is null`, `is not null`

- `in`: value must be an array
- `between`: value must be a 2-element array
- `like`: type must be string/text
- `is null` / `is not null`: value is ignored

### Filter Nesting Depth
Maximum depth is configurable (default: 3). Query `GET /ntp_drg/config` to discover. Engine throws `ReportMakerException` if exceeded.

### Virtual Attributes in Payloads
Prefix column name with `va:` — e.g., `"column": "va:lifetime_spend"`. The engine auto-resolves the SQL fragment and injects dependency models into the join plan. If the AI needs to group by a dynamic date part (like month or year), it should first use the `register_virtual_attribute` tool to create a computed column (e.g., `strftime('%Y-%m', created_at)`), then use that new virtual attribute in the payload.

### Critical Collision Rule (Aliasing)
> [!WARNING]
> If you select or group by columns with the exact same name from different models (e.g. `User.name` and `Product.name`), you MUST provide a unique `alias` for them in BOTH `selectedAttributes` and `groupBys`. Otherwise, the results will overwrite each other in the final JSON output due to SQL naming collisions.

## PHASE 7: FLUENT BUILDER API (PHP-ONLY, SERVER-SIDE)

For programmatic report construction in controllers, seeders, or scheduled tasks:

```php
use Nisalatp\DynamicReportGenerator\Builders\ReportBuilder;
use Nisalatp\DynamicReportGenerator\Facades\DynamicReport;

$request = ReportBuilder::forModel('App\Models\User')
    ->withTarget('App\Models\Order')
    ->select('App\Models\User', 'name', 'string')
    ->select('App\Models\User', 'country', 'string')
    ->groupBy('App\Models\User', 'country', 'string')
    ->aggregate('App\Models\Order', 'amount', 'integer', 'SUM', 'total_revenue')
    ->filter(function ($f) {
        $f->where('App\Models\User', 'status', '=', 'active', 'string');
        $f->nested(function ($sub) {
            $sub->where('App\Models\Order', 'amount', '>', 100, 'integer');
            $sub->orWhere('App\Models\Order', 'amount', '<', 10, 'integer');
        });
    })
    ->having(function ($f) {
        $f->where('App\Models\Order', 'amount', '>', 10000, 'integer', true);
    })
    ->build();

// Execute
$results = DynamicReport::generate($request)->get();

// Save
$saved = DynamicReport::saveReport('Revenue Report', $request, auth()->id(), 'Q2 analysis');

// Export CSV
$csv = DynamicReport::exportToCsv($request, 'revenue.csv');

// Debug SQL
$sql = DynamicReport::toRawSql(DynamicReport::generate($request));

// Inspect join plan
$plan = DynamicReport::explainJoinPlan($request);
```

### Virtual Attribute Registration (Fluent)

```php
use Nisalatp\DynamicReportGenerator\Builders\VirtualAttributeBuilder;

VirtualAttributeBuilder::create('total_revenue')
    ->forBaseModel('App\Models\User')
    ->withReturnType('integer')
    ->withSqlFragment('(SELECT SUM(orders.amount) FROM orders WHERE orders.user_id = {THIS}.id)')
    ->dependsOn(['App\Models\Order'])
    ->register();
```

### FilterBuilder Methods Reference

| Method | Signature |
|--------|-----------|
| `where` | `(string $model, string $col, string $op, mixed $val, string $type, bool $isVirtual)` |
| `orWhere` | Same as `where`, creates OR sub-group |
| `whereNull` | `(string $model, string $col, string $type)` |
| `whereNotNull` | `(string $model, string $col, string $type)` |
| `whereIn` | `(string $model, string $col, array $values, string $type)` |
| `whereBetween` | `(string $model, string $col, array $range, string $type)` |
| `nested` | `(Closure $callback, string $logic = 'and')` |
| `orNested` | `(Closure $callback)` — shorthand for `nested($cb, 'or')` |

> **See Part 3 for Frontend integration, MCP tool definitions, and the Feature Tree.**


## PHASE 8: FRONTEND INTEGRATION

The engine is **headless** — any frontend framework can consume its REST API. Below is the universal integration pattern.

### Step 1: Schema Discovery (on page load)

```javascript
// Fetch available models
const models = await fetch('/ntp_drg/models').then(r => r.json());

// For each model, fetch attributes and relationships
const schema = await fetch(`/ntp_drg/schema?model=${selectedModel}`).then(r => r.json());
// Returns: { attributes: ['id','name','email','va:lifetime_spend',...], relationships: [...] }

// Fetch config limits
const config = await fetch('/ntp_drg/config').then(r => r.json());
// Returns: { max_filter_depth: 3, max_rows: 5000 }
```

### Step 2: Build AST Payload (user interaction)

Your UI should let users:
1. Pick a `baseModel` from the models list
2. Select `targetModels` (related models to JOIN)
3. Choose `selectedAttributes` from the attributes list
4. Build `innerFilters` (WHERE) with nested AND/OR groups (respect `max_filter_depth`)
5. Add `groupBys` and `aggregates` for analytics
6. Add `outerFilters` (HAVING) for post-aggregation filtering
7. Add `sorts` for ordering

### Step 3: Execute

```javascript
// Generate report
const results = await fetch('/ntp_drg/generate', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
    body: JSON.stringify(payload) // The AST payload from Step 2
}).then(r => r.json());

// Paginate
const page = await fetch('/ntp_drg/paginate', {
    method: 'POST',
    body: JSON.stringify({ ...payload, per_page: 25 })
}).then(r => r.json());

// Export CSV (returns a file download)
const csvBlob = await fetch('/ntp_drg/export-csv', {
    method: 'POST',
    body: JSON.stringify(payload)
}).then(r => r.blob());

// Debug SQL
const debug = await fetch('/ntp_drg/debug-sql', {
    method: 'POST',
    body: JSON.stringify(payload)
}).then(r => r.json());
// Returns: { sql: "SELECT ...", join_plan: {...}, columns: [...] }
```

### Step 4: Save / Load / Manage Reports

```javascript
// Save
await fetch('/ntp_drg/reports', {
    method: 'POST',
    body: JSON.stringify({ name: 'My Report', description: 'Q2', payload: astPayload })
});

// List saved reports
const reports = await fetch('/ntp_drg/reports').then(r => r.json());

// Load into editor
const ast = await fetch(`/ntp_drg/reports/${id}/load`).then(r => r.json());

// Re-run saved report
const results = await fetch(`/ntp_drg/reports/${id}/run`).then(r => r.json());

// Update
await fetch(`/ntp_drg/reports/${id}`, {
    method: 'PUT',
    body: JSON.stringify({ name: 'Updated', description: '...', payload: astPayload })
});

// Delete
await fetch(`/ntp_drg/reports/${id}`, { method: 'DELETE' });
```

### Framework-Specific Examples

Refer to the example files in this directory for complete implementations:
- `blade/` — Laravel Blade + AlpineJS (13 examples)
- `react/` — React SPA with hooks (13 examples)
- `vue/` — Vue 3 Composition API (13 examples)
- `java/` — Java/Android HTTP client (13 examples)
- `mcp/` — MCP Tool definitions (13 examples)

Each folder contains examples for: `schema_discovery`, `build_query`, `save_query`, `load_query`, `run_saved_query`, `export_csv`, `debug_sql`, `assign_report`, `update_delete_report`, `governance_als_setup`, `model_restriction`, `register_virtual_attribute`, `audit_logs`.

---

## PHASE 9: MCP (MACHINE CONTEXT PROTOCOL) INTEGRATION

To enable AI agents to use the report generator via natural language:

### Define MCP Tools

```php
// In your MCP tool definitions file
return [
    [
        'name' => 'get_available_models',
        'description' => 'Lists all Eloquent models available for reporting',
        'handler' => fn() => DynamicReport::getAvailableModels(),
    ],
    [
        'name' => 'get_model_schema',
        'description' => 'Gets attributes and relationships for a model',
        'parameters' => ['model' => ['type' => 'string', 'required' => true]],
        'handler' => fn($params) => [
            'attributes' => DynamicReport::getModelAttributes($params['model']),
            'relationships' => DynamicReport::getModelRelationships($params['model']),
        ],
    ],
    [
        'name' => 'generate_dynamic_report',
        'description' => 'Generate a data report by providing an AST payload. The engine will auto-join tables via BFS. CRITICAL: When using aggregates, you MUST also include the aggregate column in selectedAttributes.',
        'parameters' => [
            'payload' => [
                'type' => 'object', 
                'description' => 'The flat AST payload (model and column at root of each item).',
                'required' => true
            ]
        ],
        'handler' => function ($params) {
            // NOTE: Consider adding a normalization step here to flatten nested 'attribute' 
            // objects if the LLM hallucinates them despite the prompt.
            $request = ReportBuilderRequest::fromPayload($params['payload']);
            $subjects = auth()->user()?->getDynamicReportSubjects();
            return DynamicReport::generate($request, $subjects)->get()->toArray();
        },
    ],
    [
        'name' => 'get_config',
        'description' => 'Gets engine configuration (max_filter_depth, max_rows)',
        'handler' => fn() => [
            'max_filter_depth' => DynamicReport::getMaxFilterDepth(),
            'max_rows' => config('dynamicreportgenerator.limits.max_rows'),
        ],
    ],
    [
        'name' => 'save_report',
        'description' => 'Saves a report definition for later use',
        'parameters' => [
            'name' => ['type' => 'string', 'required' => true],
            'payload' => ['type' => 'object', 'required' => true],
            'description' => ['type' => 'string'],
        ],
        'handler' => function ($params) {
            $request = ReportBuilderRequest::fromPayload($params['payload']);
            return DynamicReport::saveReport($params['name'], $request, auth()->id(), $params['description'] ?? '');
        },
    ],
    [
        'name' => 'restrict_attribute',
        'description' => 'Applies ALS masking/blocking to an attribute for a user/role',
        'parameters' => [
            'model' => ['type' => 'string', 'required' => true],
            'attribute' => ['type' => 'string', 'required' => true],
            'subject_type' => ['type' => 'string', 'required' => true],
            'subject_id' => ['type' => 'integer', 'required' => true],
            'restriction_type' => ['type' => 'string', 'enum' => ['masked', 'blocked']],
        ],
        'handler' => function ($params) {
            $subject = (new $params['subject_type'])->findOrFail($params['subject_id']);
            DynamicReport::restrictAttribute($params['model'], $params['attribute'], $subject, $params['restriction_type'] ?? 'masked');
            return ['status' => 'restricted'];
        },
    ],
    [
        'name' => 'register_virtual_attribute',
        'description' => 'Register a computed column (e.g. strftime(\'%Y-%m\', {THIS}.created_at)) for date grouping or custom metrics. CRITICAL: Prefix physical columns with {THIS}. to prevent ambiguous column errors during joins.',
        'parameters' => [
            'model' => ['type' => 'string', 'required' => true],
            'name' => ['type' => 'string', 'required' => true],
            'sql_fragment' => ['type' => 'string', 'required' => true],
            'dependencies' => [
                'type' => 'array', 
                'items' => ['type' => 'string'],
                'description' => 'Array of target model names this virtual attribute requires a JOIN to calculate. DO NOT list column names here. E.g. if it only uses columns from its base model, leave empty []. If it uses OrderItem, use ["OrderItem"]'
            ]
        ],
        'handler' => function ($params) {
            return Nisalatp\DynamicReportGenerator\Models\VirtualAttribute::create([
                'name' => $params['name'],
                'base_model' => $params['model'],
                'sql_fragment' => $params['sql_fragment'],
                'dependencies' => $params['dependencies'] ?? [],
            ])->toArray();
        }
    ],
    // ─── REPORT ACCESS CONTROL (CRITICAL for MCP agents) ───
    // These tools allow AI agents to manage who can view/execute saved reports.
    // Without these, saved reports are accessible to anyone with library access.
    [
        'name' => 'assign_report',
        'description' => 'Grant a specific user permission to view and execute a saved report. Uses the dynamic_report_user pivot table. The report owner always has access.',
        'parameters' => [
            'report_id' => ['type' => 'integer', 'required' => true, 'description' => 'The SavedReport ID'],
            'user_id' => ['type' => 'integer', 'required' => true, 'description' => 'The User ID to grant access to'],
        ],
        'handler' => function ($params) {
            DynamicReport::assignReport($params['report_id'], $params['user_id'], auth()->id());
            return ['status' => 'assigned', 'report_id' => $params['report_id'], 'user_id' => $params['user_id']];
        },
    ],
    [
        'name' => 'unassign_report',
        'description' => 'Revoke a specific user\'s permission to view and execute a saved report. Does NOT affect the report owner.',
        'parameters' => [
            'report_id' => ['type' => 'integer', 'required' => true, 'description' => 'The SavedReport ID'],
            'user_id' => ['type' => 'integer', 'required' => true, 'description' => 'The User ID to revoke access from'],
        ],
        'handler' => function ($params) {
            DynamicReport::unassignReport($params['report_id'], $params['user_id'], auth()->id());
            return ['status' => 'unassigned', 'report_id' => $params['report_id'], 'user_id' => $params['user_id']];
        },
    ],
    [
        'name' => 'get_my_reports',
        'description' => 'List only the saved reports that the current user owns or has been explicitly assigned access to.',
        'handler' => fn() => DynamicReport::getAssignedReports(auth()->id())->toArray(),
    ],
];

### MCP System Prompt Best Practices

To ensure the LLM generates valid AST payloads without hallucination, include these strict rules in your System Prompt:

1. **Flat AST Format (CRITICAL)**: Instruct the LLM to place `model` and `column` at the root of every object in `selectedAttributes`, `groupBys`, `aggregates`, and `sorts`. Explicitly forbid nesting them inside an `"attribute"` object.
2. **Aggregation Rule**: "When using aggregates, you MUST include the aggregate column in `selectedAttributes` so it is available in the inner query."
3. **Collision Prevention**: "If you select or group by columns with the exact same name from different models, you MUST provide a unique `alias` for them."
4. **Virtual Attributes Workflow**: "If you need to group by a date part (like month or year), use the `register_virtual_attribute` tool FIRST to create a computed column, then use that new attribute (prefix with `va:`) in the report payload."
5. **Virtual Attributes Ambiguity Rule**: "When registering a virtual attribute, ALWAYS prefix physical columns with `{THIS}.` (e.g., `strftime('%Y-%m', {THIS}.created_at)` instead of `strftime('%Y-%m', created_at)`). The engine dynamically aliases tables during BFS joins, so raw column names will cause ambiguous column errors."

### Payload Normalization

LLMs may occasionally hallucinate a nested schema (e.g. `{"attribute": {"model": "User", "column": "name"}}`). It is highly recommended to implement a recursive normalization function in your tool handler to automatically flatten these structures into `{"model": "User", "column": "name"}` before passing the payload to `ReportBuilderRequest::fromPayload()`.
```

### MCP Agent Workflow

1. Agent calls `get_available_models` to discover what data is available
2. Agent calls `get_model_schema` for each relevant model
3. Agent constructs an AST payload based on the user's natural language request
4. Agent calls `generate_report` with the AST
5. Agent formats and presents results to the user
6. *(Optional)* Agent calls `save_report` to persist the report for later re-use
7. *(Optional)* Agent calls `assign_report` / `unassign_report` to restrict who can view/execute the saved report
8. *(Optional)* Agent calls `get_my_reports` to list only reports the current user owns or is assigned to

---

## PHASE 10: SECURITY SETUP

### Model-Level Restriction (hide entire tables)

```php
// Restrict a model from appearing in reports
DynamicReport::restrictModel('App\Models\SecretModel', auth()->id());

// Unrestrict
DynamicReport::unrestrictModel('App\Models\SecretModel');

// List restricted
DynamicReport::getRestrictedModels(); // Returns array of FQCNs
```

### Attribute-Level Security (mask/block columns per user/role)

```php
$user = User::find(1);

// Mask: column shows '***' in output
DynamicReport::restrictAttribute('App\Models\User', 'salary', $user, 'masked');

// Block: column shows '###' and is hidden from schema discovery
DynamicReport::restrictAttribute('App\Models\User', 'ssn', $user, 'blocked');

// Remove restriction
DynamicReport::unrestrictAttribute('App\Models\User', 'salary', $user);

// View all restrictions for a subject
DynamicReport::getAttributeRestrictions($user);
```

### Governance Matrix (bulk ALS management via UI)

```php
// Get the full security matrix for a model+subject
$matrix = GovernanceManager::getMatrix('App\Models\User', 'App\Models\Role', $roleId);
// Returns: { is_reportable: true, attributes: [{name: 'email', type: 'physical', restriction: 'masked'}, ...] }

// Save the entire matrix atomically
GovernanceManager::saveMatrix('App\Models\User', 'App\Models\Role', $roleId, true, [
    'email' => 'masked',
    'ssn' => 'blocked',
    'name' => 'unrestricted',
], auth()->id());
```

---

## COMPLETE API REFERENCE

| Method | Endpoint | Body | Returns |
|--------|----------|------|---------|
| GET | `/ntp_drg/models` | — | `string[]` model FQCNs |
| GET | `/ntp_drg/schema?model=FQCN` | — | `{attributes, relationships}` |
| GET | `/ntp_drg/config` | — | `{max_filter_depth, max_rows}` |
| POST | `/ntp_drg/generate` | AST payload | `object[]` results |
| POST | `/ntp_drg/paginate` | AST + `per_page` | paginated results |
| POST | `/ntp_drg/export-csv` | AST payload | CSV file stream |
| POST | `/ntp_drg/debug-sql` | AST payload | `{sql, join_plan, columns}` |
| GET | `/ntp_drg/reports` | — | `SavedReport[]` |
| POST | `/ntp_drg/reports` | `{name, description, payload}` | `SavedReport` |
| GET | `/ntp_drg/reports/{id}/load` | — | `ReportRequest` AST |
| GET | `/ntp_drg/reports/{id}/run` | — | `object[]` results |
| PUT | `/ntp_drg/reports/{id}` | `{name, description, payload}` | `SavedReport` |
| DELETE | `/ntp_drg/reports/{id}` | — | `{message}` |
| POST | `/ntp_drg/reports/{id}/assign` | `{user_id}` | `{message}` |
| POST | `/ntp_drg/reports/{id}/unassign` | `{user_id}` | `{message}` |
| GET | `/ntp_drg/my-reports` | — | `SavedReport[]` |
| GET | `/ntp_drg/reports/{id}/logs` | — | `ReportLog[]` |
| GET | `/ntp_drg/security/restricted-models` | — | `string[]` |
| POST | `/ntp_drg/security/restrict-model` | `{model}` | `{message}` |
| POST | `/ntp_drg/security/unrestrict-model` | `{model}` | `{message}` |
| GET | `/ntp_drg/governance/matrix` | `?model&subject_type&subject_id` | `{is_reportable, attributes}` |
| POST | `/ntp_drg/governance/matrix` | `{model, subject_type, subject_id, is_reportable, attributes}` | `{message}` |
| GET | `/ntp_drg/virtual-attributes` | — | `VirtualAttribute[]` with `usage_count` |
| POST | `/ntp_drg/virtual-attributes` | `{name, base_model, return_type, sql_fragment, dependencies}` | `VirtualAttribute` |
| POST | `/ntp_drg/virtual-attributes/compile` | `{baseModel, dependencies, ast}` | `{sql_fragment}` |
| DELETE | `/ntp_drg/virtual-attributes/{id}` | `?force=true` | `{message}` |

---

## FEATURE TREE (REFERENCE)

The complete, validated feature tree is located at: `doc/verification/Package Feature Tree.md`

**Summary**: 104 total components across 17 categories:
- 7 Core Engine methods, 6 Schema Discovery, 6 Persistence, 4 Access Control
- 3 Model Security, 3 Attribute Security, 2 VA Registry, 2 Governance Manager
- 3 VA Manager, 1 VA Compiler, 21 Fluent Builder methods
- 6 HTTP/Serialization, 13 DTOs, 1 Contract, 5 Models, 5 Infrastructure, 16 Internal

**Source**: 33 PHP files, 2,676 total lines of code.

---

## CROSS-REFERENCES

When implementing, refer to these example files in the same directory:

| Feature | Blade | React | Vue | Java | MCP |
|---------|-------|-------|-----|------|-----|
| Schema Discovery | ✅ | ✅ | ✅ | ✅ | ✅ |
| Build Query | ✅ | ✅ | ✅ | ✅ | ✅ |
| Save Query | ✅ | ✅ | ✅ | ✅ | ✅ |
| Load Query | ✅ | ✅ | ✅ | ✅ | ✅ |
| Run Saved Query | ✅ | ✅ | ✅ | ✅ | ✅ |
| Export CSV | ✅ | ✅ | ✅ | ✅ | ✅ |
| Debug SQL | ✅ | ✅ | ✅ | ✅ | ✅ |
| Assign Report | ✅ | ✅ | ✅ | ✅ | ✅ |
| Update/Delete | ✅ | ✅ | ✅ | ✅ | ✅ |
| Governance/ALS | ✅ | ✅ | ✅ | ✅ | ✅ |
| Model Restriction | ✅ | ✅ | ✅ | ✅ | ✅ |
| Register VA | ✅ | ✅ | ✅ | ✅ | ✅ |
| Audit Logs | ✅ | ✅ | ✅ | ✅ | ✅ |
| Manage VAs | ✅ | — | — | — | — |
| Fluent Builder | ✅ | — | — | — | — |
| AST Reference | `AST_REFERENCE.md` (shared) | | | | |

**Total: 67 example files + this 3-part guide + Feature Tree + AST Reference = 72 documentation artifacts.**
