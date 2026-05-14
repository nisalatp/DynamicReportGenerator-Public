# Audit Logs Example: Laravel Blade (AlpineJS)

This example demonstrates how to build an audit log viewer in Blade/AlpineJS showing report lifecycle events.

## 1. Backend Route

```php
use Nisalatp\DynamicReportGenerator\Models\ReportLog;

Route::get('/admin/logs', function (Request $request) {
    $query = ReportLog::with(['savedReport', 'user'])
        ->orderBy('created_at', 'desc');

    if ($request->filled('action')) $query->where('action', $request->input('action'));
    if ($request->filled('report_id')) $query->where('saved_report_id', $request->input('report_id'));
    if ($request->filled('user_id')) $query->where('user_id', $request->input('user_id'));

    return response()->json(
        $query->limit(100)->get()->map(fn ($log) => [
            'id' => $log->id,
            'action' => $log->action,
            'saved_report_id' => $log->saved_report_id,
            'report_name' => $log->savedReport?->name,
            'user_id' => $log->user_id,
            'user_name' => $log->user?->name,
            'details' => $log->details,
            'row_count' => $log->row_count,
            'created_at' => $log->created_at,
        ])
    );
})->middleware('auth');
```

## 2. The Frontend Implementation

```html
<div x-data="auditLogViewer()" x-init="fetchLogs()">
    <h3>Audit Log Viewer</h3>

    <!-- Filters -->
    <div class="filter-bar mb-3 d-flex gap-2">
        <select x-model="filters.action" @change="fetchLogs" class="form-select" style="width: auto;">
            <option value="">All Actions</option>
            <option value="created">Created</option>
            <option value="updated">Updated</option>
            <option value="executed">Executed</option>
            <option value="assigned">Assigned</option>
            <option value="unassigned">Unassigned</option>
            <option value="deleted">Deleted</option>
            <option value="error">Errors</option>
        </select>
        <input x-model="filters.reportId" type="text" placeholder="Report ID" class="form-control" style="width: 120px;" />
        <input x-model="filters.userId" type="text" placeholder="User ID" class="form-control" style="width: 120px;" />
        <button @click="fetchLogs" :disabled="loading" class="btn btn-primary">Search</button>
    </div>

    <!-- Log Table -->
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Timestamp</th><th>Action</th><th>Report</th><th>User</th><th>Details</th>
            </tr>
        </thead>
        <tbody>
            <template x-for="log in logs" :key="log.id">
                <tr :class="{ 'table-danger': log.action === 'error' }">
                    <td x-text="new Date(log.created_at).toLocaleString()"></td>
                    <td>
                        <span :class="badgeClass(log.action)" x-text="log.action"></span>
                    </td>
                    <td x-text="log.report_name || log.saved_report_id || '—'"></td>
                    <td x-text="log.user_name || log.user_id || 'System'"></td>
                    <td>
                        <template x-if="log.action === 'error'">
                            <details>
                                <summary>View Error</summary>
                                <pre class="text-danger small" x-text="log.details"></pre>
                            </details>
                        </template>
                        <template x-if="log.action === 'executed'">
                            <span x-text="'Rows: ' + (log.row_count || 'N/A')"></span>
                        </template>
                        <template x-if="log.action === 'assigned'">
                            <span x-text="'To User #' + log.details"></span>
                        </template>
                    </td>
                </tr>
            </template>
        </tbody>
    </table>

    <p x-show="logs.length === 0 && !loading" class="text-muted">No audit logs found.</p>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('auditLogViewer', () => ({
        logs: [],
        loading: false,
        filters: { action: '', reportId: '', userId: '' },

        async fetchLogs() {
            this.loading = true;
            try {
                const params = new URLSearchParams();
                if (this.filters.action) params.append('action', this.filters.action);
                if (this.filters.reportId) params.append('report_id', this.filters.reportId);
                if (this.filters.userId) params.append('user_id', this.filters.userId);

                const res = await fetch(`/admin/logs?${params.toString()}`);
                this.logs = await res.json();
            } finally { this.loading = false; }
        },

        badgeClass(action) {
            const map = {
                executed: 'badge bg-success', error: 'badge bg-danger',
                deleted: 'badge bg-warning', created: 'badge bg-info',
                assigned: 'badge bg-primary', unassigned: 'badge bg-secondary',
                updated: 'badge bg-info'
            };
            return map[action] || 'badge bg-secondary';
        }
    }));
});
</script>
```

> [!NOTE]
> The `dynamic_report_logs` table automatically records: `created`, `updated`, `executed`, `assigned`, `unassigned`, `deleted`, and `error` actions. Error logs include full exception stack traces.
