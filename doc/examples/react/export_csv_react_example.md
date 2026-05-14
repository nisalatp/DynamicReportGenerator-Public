# CSV Export Example: React (Hooks)

This example demonstrates how to trigger a streaming CSV export of a dynamic report directly from a React application. The backend uses `cursor()` to stream rows one at a time, so the file never needs to be buffered in server memory.

> [!IMPORTANT]
> Please refer to the [AST Reference Guide](../AST_REFERENCE.md) for the complete, definitive constraints and validation rules of the payload structure.

## The Frontend Implementation

```jsx
import React, { useState } from 'react';

export default function ReportExporter() {
  const [loading, setLoading] = useState(false);
  const [filename, setFilename] = useState('report_export.csv');

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
      },
      {
        attribute: { modelClass: 'Order', column: 'id', type: 'integer' },
        function: 'COUNT',
        alias: 'order_count'
      }
    ],
    innerFilters: null,
    outerFilters: null,
    sorts: [
      {
        attribute: { modelClass: 'Order', column: 'total_revenue', isVirtual: true },
        direction: 'DESC'
      }
    ]
  };

  const exportCsv = async () => {
    setLoading(true);
    try {
      const response = await fetch('/api/report/export-csv', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ payload, filename })
      });

      // The response is a StreamedResponse — receive it as a Blob
      const blob = await response.blob();
      
      // Trigger browser download
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    } catch (error) {
      console.error("CSV export failed", error);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div>
      <h2>Export Report to CSV</h2>

      <div className="form-group">
        <label>Filename:</label>
        <input 
          type="text" 
          value={filename} 
          onChange={e => setFilename(e.target.value)} 
          placeholder="report_export.csv"
        />
      </div>

      <button onClick={exportCsv} disabled={loading}>
        {loading ? 'Exporting...' : 'Download CSV'}
      </button>

      <p className="info">
        The backend uses <code>cursor()</code>-based streaming, so even 
        million-row exports consume O(1) server memory.
      </p>
    </div>
  );
}
```

---

## Backend Controller Example

```php
// In your Laravel controller
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

> [!TIP]
> **Zero-Memory Buffering**: The `exportToCsv()` method uses a Symfony `StreamedResponse` backed by a database `cursor()`. Each row is fetched one at a time and piped directly over HTTP. You can safely export 10GB of data from a 512MB server.
