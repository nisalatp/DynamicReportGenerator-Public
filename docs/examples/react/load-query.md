# Load Query Example: React (Hooks)

> **Backend package — `nisalatp/dynamicreportgenerator` (v1.0.0, MIT):** this frontend example drives the
> published Composer package. Install and configure the Laravel backend first, register your reportable models
> in `config/dynamicreportgenerator.php`, and expose JSON endpoints via the `DynamicReport` facade
> (`generate`, `getAvailableModels`, `getModelAttributes`, `saveReport`, `loadAndGenerate`). Payload schema: [AST_REFERENCE.md](reference/ast-reference).

```bash
composer require nisalatp/dynamicreportgenerator
```


This example shows how to fetch a saved report from the database and load its AST back into the React state.

## The Frontend Implementation

```jsx
import React, { useState, useEffect } from 'react';

export default function ReportLibrary({ onSelectReport }) {
  const [savedReports, setSavedReports] = useState([]);
  const [loading, setLoading] = useState(false);

  const fetchSavedReports = async () => {
    setLoading(true);
    try {
      const response = await fetch('/api/report/saved');
      const data = await response.json();
      setSavedReports(data);
    } catch (error) {
      console.error("Error fetching reports", error);
    } finally {
      setLoading(false);
    }
  };

  const loadToEditor = (payload) => {
    const parsedAst = typeof payload === 'string' ? JSON.parse(payload) : payload;
    
    // Pass the AST back to the parent Builder component
    // setPayload(parsedAst) inside the parent will re-render the UI automatically
    onSelectReport(parsedAst);
  };

  return (
    <div className="library">
      <h2>Saved Reports Library</h2>
      <button onClick={fetchSavedReports}>
        {loading ? 'Loading...' : 'Browse Library'}
      </button>

      {savedReports.length > 0 && (
        <ul>
          {savedReports.map(report => (
            <li key={report.id}>
              <strong>{report.name}</strong>
              <p>{report.description}</p>
              <button onClick={() => loadToEditor(report.payload)}>
                Load into Editor
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst opens the Saved Reports Library and clicks on "High Value Segment Analysis" (analyzing active user purchasing behavior across specific product categories). They want to load this report back into the visual editor so they can modify the HAVING clause filters.

**Models Used**: `User`, `Order`, `Product`.

When the user clicks "Load into Editor", the React state is updated via `onSelectReport()`. Because React maps the UI directly to state, updating the parent payload to this exact JSON AST instantly forces the entire builder UI to display the exact parameters that were saved:

```json
{
  "baseModel": "User",
  "targetModels": ["Order", "Product"],
  "selectedAttributes": [],
  "groupBys": [
    { "attribute": { "modelClass": "User", "column": "country", "type": "string" } },
    { "attribute": { "modelClass": "Product", "column": "category", "type": "string" } }
  ],
  "aggregates": [
    { 
      "attribute": { "modelClass": "Order", "column": "amount", "type": "integer" },
      "function": "SUM",
      "alias": "total_revenue"
    },
    { 
      "attribute": { "modelClass": "Order", "column": "id", "type": "integer" },
      "function": "COUNT",
      "alias": "total_orders"
    }
  ],
  "innerFilters": {
    "type": "group",
    "logic": "and",
    "children": [
      {
        "type": "leaf",
        "attribute": { "modelClass": "User", "column": "status", "type": "string" },
        "operator": "=",
        "value": "active"
      },
      {
        "type": "group",
        "logic": "or",
        "children": [
            {
                "type": "leaf",
                "attribute": { "modelClass": "Product", "column": "category", "type": "string" },
                "operator": "=",
                "value": "Electronics"
            },
            {
                "type": "leaf",
                "attribute": { "modelClass": "Product", "column": "category", "type": "string" },
                "operator": "=",
                "value": "Software"
            }
        ]
      }
    ]
  },
  "outerFilters": {
    "type": "group",
    "logic": "and",
    "children": [
        {
            "type": "leaf",
            "attribute": { "modelClass": "Order", "column": "amount", "type": "integer", "isVirtual": true },
            "operator": ">",
            "value": 10000
        },
        {
            "type": "leaf",
            "attribute": { "modelClass": "Order", "column": "id", "type": "integer", "isVirtual": true },
            "operator": ">",
            "value": 5
        }
    ]
  }
}
```
