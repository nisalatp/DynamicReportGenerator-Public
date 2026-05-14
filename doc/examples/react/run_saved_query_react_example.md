# Run Saved Query Example: React (Hooks)

This example shows how to directly execute a saved report configuration from the database without needing to load its AST into the builder first.

## The Frontend Implementation

```jsx
import React, { useState } from 'react';

export default function ReportRunner() {
  const [savedReports, setSavedReports] = useState([]);
  const [results, setResults] = useState([]);
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

  const executeSavedReport = async (id) => {
    setLoading(true);
    try {
      // Notice we hit the /execute endpoint and only pass the ID.
      // The backend handles loading the AST and compiling the SQL.
      const response = await fetch(`/api/report/saved/${id}/execute`, {
        method: 'POST'
      });
      const data = await response.json();
      setResults(data.data);
    } catch (error) {
      console.error("Failed to execute saved report", error);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="library">
      <h2>Saved Reports Library</h2>
      <button onClick={fetchSavedReports}>Browse Library</button>

      {savedReports.length > 0 && (
        <ul>
          {savedReports.map(report => (
            <li key={report.id}>
              <strong>{report.name}</strong>
              <button 
                onClick={() => executeSavedReport(report.id)}
                disabled={loading}
              >
                Run Report Directly
              </button>
            </li>
          ))}
        </ul>
      )}

      {results.length > 0 && (
        <div>
          <h3>Results:</h3>
          <pre>{JSON.stringify(results, null, 2)}</pre>
        </div>
      )}
    </div>
  );
}
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst opens the Saved Reports Library and clicks on "High Value Segment Analysis" (analyzing active user purchasing behavior across specific product categories). They do NOT want to modify it. They just want to see the latest data. They click "Run Report Directly".

**Models Used**: `User`, `Order`, `Product`.

The frontend only passes the `report.id`. The backend fetches the record from the database and implicitly hydrates this massive AST payload. It then validates it, compiles it into SQL, executes it, and returns the data array directly to React:

```json
{
    "baseModel": "User",
    "targetModels": [
        "Order",
        "Product"
    ],
    "selectedAttributes": [],
    "groupBys": [
        {
            "attribute": {
                "modelClass": "User",
                "column": "country",
                "dataType": "string"
            }
        },
        {
            "attribute": {
                "modelClass": "Product",
                "column": "category",
                "dataType": "string"
            }
        }
    ],
    "aggregates": [
        {
            "attribute": {
                "modelClass": "Order",
                "column": "amount",
                "dataType": "integer"
            },
            "function": "SUM",
            "alias": "total_revenue"
        },
        {
            "attribute": {
                "modelClass": "Order",
                "column": "id",
                "dataType": "integer"
            },
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
                "attribute": {
                    "modelClass": "User",
                    "column": "status",
                    "dataType": "string"
                },
                "operator": "=",
                "value": "active"
            },
            {
                "type": "group",
                "logic": "or",
                "children": [
                    {
                        "type": "leaf",
                        "attribute": {
                            "modelClass": "Product",
                            "column": "category",
                            "dataType": "string"
                        },
                        "operator": "=",
                        "value": "Electronics"
                    },
                    {
                        "type": "leaf",
                        "attribute": {
                            "modelClass": "Product",
                            "column": "category",
                            "dataType": "string"
                        },
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
                "attribute": {
                    "modelClass": "Order",
                    "column": "amount",
                    "dataType": "integer",
                    "isVirtual": true
                },
                "operator": ">",
                "value": 10000
            },
            {
                "type": "leaf",
                "attribute": {
                    "modelClass": "Order",
                    "column": "id",
                    "dataType": "integer",
                    "isVirtual": true
                },
                "operator": ">",
                "value": 5
            }
        ]
    }
}
```
