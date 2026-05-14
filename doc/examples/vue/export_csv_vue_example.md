# CSV Export Example: Vue 3 (Composition API)

This example demonstrates how to trigger a streaming CSV export of a dynamic report from a Vue 3 application. The backend uses `cursor()` to stream rows one at a time.

> [!IMPORTANT]
> Please refer to the [AST Reference Guide](../AST_REFERENCE.md) for the complete payload structure.

## The Frontend Implementation

```vue
<template>
  <div class="report-exporter">
    <h2>Export Report to CSV</h2>

    <div class="form-group">
      <label>Filename:</label>
      <input v-model="filename" type="text" placeholder="report_export.csv" />
    </div>

    <button @click="exportCsv" :disabled="loading">
      {{ loading ? 'Exporting...' : 'Download CSV' }}
    </button>

    <p class="info">
      The backend uses <code>cursor()</code>-based streaming — even million-row exports consume O(1) server memory.
    </p>
  </div>
</template>

<script setup>
import { ref } from 'vue';

const loading = ref(false);
const filename = ref('report_export.csv');

const payload = {
  baseModel: 'User',
  targetModels: ['Order'],
  selectedAttributes: [
    { modelClass: 'User', column: 'name', type: 'string' },
    { modelClass: 'User', column: 'email', type: 'string' },
    { modelClass: 'User', column: 'country', type: 'string' }
  ],
  groupBys: [
    { attribute: { modelClass: 'User', column: 'country', type: 'string' } }
  ],
  aggregates: [
    {
      attribute: { modelClass: 'Order', column: 'amount', type: 'integer' },
      function: 'SUM',
      alias: 'total_revenue'
    }
  ],
  innerFilters: null,
  outerFilters: null,
  sorts: [
    { attribute: { modelClass: 'Order', column: 'total_revenue', isVirtual: true }, direction: 'DESC' }
  ]
};

const exportCsv = async () => {
  loading.value = true;
  try {
    const response = await fetch('/api/report/export-csv', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ payload, filename: filename.value })
    });

    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename.value;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  } catch (error) {
    console.error("CSV export failed", error);
  } finally {
    loading.value = false;
  }
};
</script>
```

---

## Backend Controller Example

```php
use Nisalatp\DynamicReportGenerator\Facades\DynamicReport;
use Nisalatp\DynamicReportGenerator\Http\Requests\ReportBuilderRequest;

public function exportCsv(Request $request)
{
    $reportRequest = ReportBuilderRequest::fromPayload($request->input('payload'));
    $subjects = auth()->user()->getDynamicReportSubjects();

    return DynamicReport::exportToCsv(
        $reportRequest,
        $request->input('filename', 'export.csv'),
        $subjects
    );
}
```
