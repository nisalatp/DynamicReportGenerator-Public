# Update & Delete Report Example: Laravel Blade (AlpineJS)

This example demonstrates how to update and delete saved reports from a Blade/AlpineJS interface.

## 1. Backend Routes

```php
use Nisalatp\DynamicReportGenerator\Facades\DynamicReport;

Route::put('/report/saved/{id}', function (Request $request, int $id) {
    $reportRequest = $request->has('payload')
        ? ReportBuilderRequest::fromPayload($request->input('payload'))
        : null;

    return response()->json(DynamicReport::updateReport(
        $id, $request->input('name'), $reportRequest,
        $request->input('description', ''), auth()->id()
    ));
})->middleware('auth');

Route::delete('/report/saved/{id}', function (int $id) {
    DynamicReport::deleteReport($id, auth()->id());
    return response()->json(['message' => 'Deleted']);
})->middleware('auth');
```

## 2. The Frontend Implementation

```html
<div x-data="reportManager()" x-init="fetchReports()">
    <h3>Saved Reports</h3>

    <table class="table">
        <thead>
            <tr><th>Name</th><th>Description</th><th>Actions</th></tr>
        </thead>
        <tbody>
            <template x-for="report in reports" :key="report.id">
                <tr>
                    <td x-text="report.name"></td>
                    <td x-text="report.description || '—'"></td>
                    <td>
                        <button @click="startEdit(report)" class="btn btn-sm btn-primary">Edit</button>
                        <button @click="deleteReport(report.id)" class="btn btn-sm btn-danger">Delete</button>
                    </td>
                </tr>
            </template>
        </tbody>
    </table>

    <!-- Edit Modal -->
    <div x-show="editing" class="modal-overlay" @click.self="editing = null">
        <div class="modal-content">
            <h4>Edit Report</h4>
            <input type="text" x-model="editName" placeholder="Report Name" class="form-control mb-2" />
            <textarea x-model="editDescription" placeholder="Description" class="form-control mb-2"></textarea>
            <button @click="saveEdit" :disabled="saving" class="btn btn-primary">
                <span x-text="saving ? 'Saving...' : 'Save Changes'"></span>
            </button>
            <button @click="editing = null" class="btn btn-secondary">Cancel</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('reportManager', () => ({
        reports: [],
        editing: null,
        editName: '',
        editDescription: '',
        saving: false,

        async fetchReports() {
            const res = await fetch('/report/saved');
            this.reports = await res.json();
        },

        startEdit(report) {
            this.editing = report;
            this.editName = report.name;
            this.editDescription = report.description || '';
        },

        async saveEdit() {
            this.saving = true;
            await fetch(`/report/saved/${this.editing.id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ name: this.editName, description: this.editDescription })
            });
            this.editing = null;
            this.saving = false;
            await this.fetchReports();
        },

        async deleteReport(id) {
            if (!confirm('Delete this report?')) return;
            await fetch(`/report/saved/${id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
            });
            await this.fetchReports();
        }
    }));
});
</script>
```

> [!NOTE]
> Both operations are automatically recorded in `dynamic_report_logs`.
