# Debug SQL & Join Plan Example: Laravel Blade (AlpineJS)

This example demonstrates how to build an SQL debugger panel in Blade/AlpineJS that shows the raw compiled SQL and the BFS join plan.

## 1. Backend Routes

```php
use Nisalatp\DynamicReportGenerator\Facades\DynamicReport;
use Nisalatp\DynamicReportGenerator\Http\Requests\ReportBuilderRequest;

Route::post('/report/debug/sql', function (Request $request) {
    $reportRequest = ReportBuilderRequest::fromPayload($request->all());
    $query = DynamicReport::generate($reportRequest);
    return response()->json(['sql' => DynamicReport::toRawSql($query)]);
})->middleware('auth');

Route::post('/report/debug/join-plan', function (Request $request) {
    $reportRequest = ReportBuilderRequest::fromPayload($request->all());
    return response()->json(DynamicReport::explainJoinPlan($reportRequest));
})->middleware('auth');
```

## 2. The Frontend Implementation

```html
<div x-data="sqlDebugger()">
    <h3>SQL Debugger & Join Plan Inspector</h3>

    <div class="btn-group mb-3">
        <button @click="fetchRawSql" :disabled="loading" class="btn btn-outline-primary">
            Show Raw SQL
        </button>
        <button @click="fetchJoinPlan" :disabled="loading" class="btn btn-outline-info">
            Show BFS Join Plan
        </button>
    </div>

    <!-- Raw SQL Output -->
    <div x-show="rawSql" class="card mb-3">
        <div class="card-header">Compiled SQL</div>
        <div class="card-body">
            <pre class="sql-output" x-text="rawSql"></pre>
        </div>
    </div>

    <!-- Join Plan Table -->
    <div x-show="joinPlan" class="card">
        <div class="card-header">BFS Join Plan</div>
        <div class="card-body">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>From</th><th>To</th><th>Type</th>
                        <th>Direction</th><th>Local Key</th><th>Foreign Key</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="step in joinPlan?.steps || []" :key="step.fromModel + step.toModel">
                        <tr>
                            <td x-text="step.fromModel"></td>
                            <td x-text="step.toModel"></td>
                            <td x-text="step.relationType"></td>
                            <td>
                                <span :class="step.direction === 'reverse' ? 'badge bg-warning' : 'badge bg-success'"
                                      x-text="step.direction"></span>
                            </td>
                            <td><code x-text="step.localKey"></code></td>
                            <td><code x-text="step.foreignKey"></code></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('sqlDebugger', () => ({
        rawSql: '',
        joinPlan: null,
        loading: false,

        async fetchRawSql() {
            this.loading = true;
            try {
                const res = await fetch('/report/debug/sql', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify(this.$root.__x.$data.payload)
                });
                const data = await res.json();
                this.rawSql = data.sql;
            } finally { this.loading = false; }
        },

        async fetchJoinPlan() {
            this.loading = true;
            try {
                const res = await fetch('/report/debug/join-plan', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify(this.$root.__x.$data.payload)
                });
                this.joinPlan = await res.json();
            } finally { this.loading = false; }
        }
    }));
});
</script>
```

> [!TIP]
> The `direction` field shows `forward` for explicitly declared Eloquent relationships and `reverse` for edges that were automatically synthesized by the engine's bidirectional graph construction.
