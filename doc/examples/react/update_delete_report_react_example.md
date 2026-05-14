# Update & Delete Report Example: React (Hooks)

This example demonstrates how to update an existing saved report's configuration and how to delete reports from the library.

## The Frontend Implementation

```jsx
import React, { useState, useEffect } from 'react';

export default function ReportManager() {
  const [savedReports, setSavedReports] = useState([]);
  const [editingReport, setEditingReport] = useState(null);
  const [editName, setEditName] = useState('');
  const [editDescription, setEditDescription] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => { fetchReports(); }, []);

  const fetchReports = async () => {
    const res = await fetch('/api/report/saved');
    setSavedReports(await res.json());
  };

  // ─── Update Report ──────────────────────────────────────────
  const startEditing = (report) => {
    setEditingReport(report);
    setEditName(report.name);
    setEditDescription(report.description || '');
  };

  const updateReport = async () => {
    setLoading(true);
    try {
      await fetch(`/api/report/saved/${editingReport.id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: editName,
          description: editDescription,
          // Optionally include a modified payload to update the AST
          // payload: modifiedPayload
        })
      });
      setEditingReport(null);
      await fetchReports(); // Refresh list
    } catch (error) {
      console.error("Failed to update report", error);
    } finally {
      setLoading(false);
    }
  };

  // ─── Delete Report ──────────────────────────────────────────
  const deleteReport = async (id) => {
    if (!window.confirm('Are you sure you want to delete this report?')) return;
    
    setLoading(true);
    try {
      await fetch(`/api/report/saved/${id}`, { method: 'DELETE' });
      await fetchReports(); // Refresh list
    } catch (error) {
      console.error("Failed to delete report", error);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="report-manager">
      <h2>Report Library</h2>
      
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Description</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          {savedReports.map(report => (
            <tr key={report.id}>
              <td>{report.name}</td>
              <td>{report.description || '—'}</td>
              <td>{new Date(report.created_at).toLocaleDateString()}</td>
              <td>
                <button onClick={() => startEditing(report)}>Edit</button>
                <button onClick={() => deleteReport(report.id)} className="btn-danger">
                  Delete
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {editingReport && (
        <div className="edit-modal">
          <h3>Edit Report: {editingReport.name}</h3>
          <input 
            type="text" 
            value={editName} 
            onChange={e => setEditName(e.target.value)} 
            placeholder="Report Name"
          />
          <textarea 
            value={editDescription} 
            onChange={e => setEditDescription(e.target.value)} 
            placeholder="Description"
          />
          <button onClick={updateReport} disabled={loading}>
            {loading ? 'Saving...' : 'Save Changes'}
          </button>
          <button onClick={() => setEditingReport(null)}>Cancel</button>
        </div>
      )}
    </div>
  );
}
```

---

## Backend Controller Example

```php
use Nisalatp\DynamicReportGenerator\Facades\DynamicReport;
use Nisalatp\DynamicReportGenerator\Http\Requests\ReportBuilderRequest;

// Update
public function update(Request $request, int $id)
{
    $reportRequest = $request->has('payload') 
        ? ReportBuilderRequest::fromPayload($request->input('payload'))
        : null;

    $report = DynamicReport::updateReport(
        $id,
        $request->input('name'),
        $reportRequest,
        $request->input('description', ''),
        auth()->id()
    );

    return response()->json($report);
}

// Delete
public function destroy(int $id)
{
    DynamicReport::deleteReport($id, auth()->id());
    return response()->json(['message' => 'Report deleted']);
}
```

> [!NOTE]
> Both `updateReport()` and `deleteReport()` automatically log the action to `dynamic_report_logs` for audit compliance.
