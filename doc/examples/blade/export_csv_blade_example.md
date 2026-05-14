# CSV Export Example: Laravel Blade (AlpineJS)

This example demonstrates how to trigger a streaming CSV export from a Blade/AlpineJS interface.

## 1. Backend Route

```php
use Nisalatp\DynamicReportGenerator\Facades\DynamicReport;
use Nisalatp\DynamicReportGenerator\Http\Requests\ReportBuilderRequest;

Route::post('/report/export-csv', function (Request $request) {
    $reportRequest = ReportBuilderRequest::fromPayload($request->input('payload'));
    $subjects = auth()->user()->getDynamicReportSubjects();
    
    return DynamicReport::exportToCsv(
        $reportRequest,
        $request->input('filename', 'export.csv'),
        $subjects
    );
})->middleware('auth');
```

## 2. The Frontend Implementation

```html
<div x-data="csvExporter()">
    <h3>Export Report</h3>

    <div class="form-group">
        <label>Filename:</label>
        <input type="text" x-model="filename" placeholder="report_export.csv" />
    </div>

    <button @click="exportCsv" :disabled="loading">
        <span x-show="!loading">Download CSV</span>
        <span x-show="loading">Exporting...</span>
    </button>

    <p class="text-muted mt-2">
        Uses <code>cursor()</code>-based streaming — O(1) server memory regardless of export size.
    </p>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('csvExporter', () => ({
        loading: false,
        filename: 'report_export.csv',

        async exportCsv() {
            this.loading = true;
            try {
                const response = await fetch('/report/export-csv', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        payload: this.$root.__x.$data.payload, // Reuse the builder's payload
                        filename: this.filename
                    })
                });

                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = this.filename;
                document.body.appendChild(a);
                a.click();
                a.remove();
                window.URL.revokeObjectURL(url);
            } catch (error) {
                console.error("CSV export failed", error);
            } finally {
                this.loading = false;
            }
        }
    }));
});
</script>
```

> [!TIP]
> **Zero-Memory Buffering**: The backend never constructs the CSV in memory. Each row is fetched via a database cursor and piped directly over HTTP as a `StreamedResponse`.
