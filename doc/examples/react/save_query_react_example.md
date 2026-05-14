# Save Query Example: React (Hooks)

This example shows how to save a generated report AST payload into the database using React.

## The Frontend Implementation

```jsx
import React, { useState } from 'react';

export default function SaveReportPanel({ currentAst }) {
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState('');
  const [form, setForm] = useState({ name: '', description: '' });

  const saveReport = async () => {
    if (!form.name) return;
    
    setLoading(true);
    setSuccess('');
    
    try {
      const response = await fetch('/api/report/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: form.name,
          description: form.description,
          payload: currentAst // Pass the strict AST object natively
        })
      });
      
      if (response.ok) {
        setSuccess('Report saved successfully!');
        setForm({ name: '', description: '' }); 
      }
    } catch (error) {
      console.error("Failed to save report", error);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="save-panel">
      <h2>Save Report to Library</h2>
      
      <div>
        <label>Report Name</label>
        <input 
          type="text" 
          value={form.name}
          onChange={e => setForm({...form, name: e.target.value})}
        />
      </div>
      
      <div>
        <label>Description</label>
        <textarea 
          value={form.description}
          onChange={e => setForm({...form, description: e.target.value})}
        />
      </div>
      
      <button onClick={saveReport} disabled={loading || !form.name}>
        {loading ? 'Saving...' : 'Save Configuration'}
      </button>
      
      {success && <p style={{ color: 'green' }}>{success}</p>}
    </div>
  );
}
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst wants to analyze active user purchasing behavior across specific product categories. They want to see the total revenue and order count, grouped by the user's country and the product's category. They only want to see groupings that generated more than $10,000 in revenue and had more than 5 orders. They have just built this query and want to save it as "High Value Segment Analysis".

**Models Used**: `User`, `Order`, `Product`.

When the user activates the React `saveReport` function, the `currentAst` prop is packaged into this exact JSON payload, capturing the exact structural state of the dynamic query to be saved into the Laravel backend:

```json
{
  "name": "High Value Segment Analysis",
  "description": "Active users buying electronics/software with revenue > $10k",
  "payload": {
    "baseModel": "User",
    "targetModels": ["Order", "Product"],
    "selectedAttributes": [],
    "groupBys": [
      { "attribute": { "modelClass": "User", "column": "country", "dataType": "string" } },
      { "attribute": { "modelClass": "Product", "column": "category", "dataType": "string" } }
    ],
    "aggregates": [
      { 
        "attribute": { "modelClass": "Order", "column": "amount", "dataType": "integer" },
        "function": "SUM",
        "alias": "total_revenue"
      },
      { 
        "attribute": { "modelClass": "Order", "column": "id", "dataType": "integer" },
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
          "attribute": { "modelClass": "User", "column": "status", "dataType": "string" },
          "operator": "=",
          "value": "active"
        },
        {
          "type": "group",
          "logic": "or",
          "children": [
              {
                  "type": "leaf",
                  "attribute": { "modelClass": "Product", "column": "category", "dataType": "string" },
                  "operator": "=",
                  "value": "Electronics"
              },
              {
                  "type": "leaf",
                  "attribute": { "modelClass": "Product", "column": "category", "dataType": "string" },
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
              "attribute": { "modelClass": "Order", "column": "amount", "dataType": "integer", "isVirtual": true },
              "operator": ">",
              "value": 10000
          },
          {
              "type": "leaf",
              "attribute": { "modelClass": "Order", "column": "id", "dataType": "integer", "isVirtual": true },
              "operator": ">",
              "value": 5
          }
      ]
    }
  }
}
```
