# Build Query Example: React (Hooks)

This example demonstrates how to build a highly complex dynamic query payload using React Functional Components and standard state hooks. 

> [!IMPORTANT]
> Please refer to the [AST Reference Guide](../AST_REFERENCE.md) for the complete, definitive constraints and validation rules of the payload structure.

## The Frontend Implementation

```jsx
import React, { useState } from 'react';

export default function ReportBuilder() {
  const [loading, setLoading] = useState(false);
  const [results, setResults] = useState([]);
  
  const [payload, setPayload] = useState({
    baseModel: 'User',
    targetModels: ['Order', 'Product'],
    selectedAttributes: [],
    innerFilters: null,
    groupBys: [],
    aggregates: [],
    outerFilters: null
  });

  const addMultipleGroupBys = () => {
    setPayload(prev => ({
        ...prev,
        groupBys: [
            ...prev.groupBys,
            { attribute: { model: 'User', column: 'country', type: 'string' } },
            { attribute: { model: 'Product', column: 'category', type: 'string' } }
        ]
    }));
  };

  const addMultipleAggregates = () => {
    setPayload(prev => ({
        ...prev,
        aggregates: [
            ...prev.aggregates,
            {
                attribute: { model: 'Order', column: 'amount', type: 'integer' },
                function: 'SUM',
                alias: 'total_revenue'
            },
            {
                attribute: { model: 'Order', column: 'id', type: 'integer' },
                function: 'COUNT',
                alias: 'total_orders'
            }
        ]
    }));
  };

  const addComplexFilterGroup = () => {
      setPayload(prev => ({
          ...prev,
          innerFilters: {
              type: 'group',
              logic: 'and',
              children: [
                  {
                      type: 'leaf',
                      attribute: { model: 'User', column: 'status', type: 'string' },
                      operator: '=',
                      value: 'active'
                  },
                  {
                      type: 'group',
                      logic: 'or',
                      children: [
                          {
                              type: 'leaf',
                              attribute: { model: 'Product', column: 'category', type: 'string' },
                              operator: '=',
                              value: 'Electronics'
                          },
                          {
                              type: 'leaf',
                              attribute: { model: 'Product', column: 'category', type: 'string' },
                              operator: '=',
                              value: 'Software'
                          }
                      ]
                  }
              ]
          }
      }));
  };

  const addComplexHavingFilter = () => {
      setPayload(prev => ({
          ...prev,
          outerFilters: {
               type: 'group',
               logic: 'and',
               children: [
                   {
                       type: 'leaf',
                       attribute: { model: 'Order', column: 'amount', type: 'integer', isVirtual: true },
                       operator: '>',
                       value: 10000
                   },
                   {
                       type: 'leaf',
                       attribute: { model: 'Order', column: 'id', type: 'integer', isVirtual: true },
                       operator: '>',
                       value: 5
                   }
               ]
          }
      }));
  };

  const generateReport = async () => {
    setLoading(true);
    try {
      const response = await fetch('/api/report/generate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await response.json();
      setResults(data.data);
    } catch (error) {
      console.error("Failed to generate report", error);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div>
      <h2>1. Select Base Model</h2>
      <select 
        value={payload.baseModel} 
        onChange={e => setPayload({...payload, baseModel: e.target.value})}
      >
        <option value="User">Users</option>
      </select>

      <h2>2. Complex Groupings & Aggregations</h2>
      <button onClick={addMultipleGroupBys}>Group by User Country AND Product Category</button>
      <button onClick={addMultipleAggregates}>Calculate Total Revenue AND Order Count</button>

      <h2>3. Complex Filters</h2>
      <button onClick={addComplexFilterGroup}>Add "Active Users buying Electronics/Software" (Inner Filter)</button>
      <button onClick={addComplexHavingFilter}>Add "Revenue > 10k AND Orders > 5" (Outer Filter)</button>

      <h2>4. Generate Report</h2>
      <button onClick={generateReport} disabled={loading}>
        {loading ? 'Generating...' : 'Execute Query'}
      </button>

      {results.length > 0 && (
        <pre>{JSON.stringify(results, null, 2)}</pre>
      )}
    </div>
  );
}
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst wants to analyze active user purchasing behavior across specific product categories. They want to see the total revenue and order count, grouped by the user's country and the product's category. They only want to see groupings that generated more than $10,000 in revenue and had more than 5 orders.

**Models Used**: `User`, `Order`, `Product`.

When the user activates the React state components above, the `payload` object exactly matches the following AST representation:

```json
{
  "baseModel": "User",
  "targetModels": ["Order", "Product"],
  "selectedAttributes": [],
  "groupBys": [
    { "attribute": { "model": "User", "column": "country", "type": "string" } },
    { "attribute": { "model": "Product", "column": "category", "type": "string" } }
  ],
  "aggregates": [
    { 
      "attribute": { "model": "Order", "column": "amount", "type": "integer" },
      "function": "SUM",
      "alias": "total_revenue"
    },
    { 
      "attribute": { "model": "Order", "column": "id", "type": "integer" },
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
        "attribute": { "model": "User", "column": "status", "type": "string" },
        "operator": "=",
        "value": "active"
      },
      {
        "type": "group",
        "logic": "or",
        "children": [
            {
                "type": "leaf",
                "attribute": { "model": "Product", "column": "category", "type": "string" },
                "operator": "=",
                "value": "Electronics"
            },
            {
                "type": "leaf",
                "attribute": { "model": "Product", "column": "category", "type": "string" },
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
            "attribute": { "model": "Order", "column": "amount", "type": "integer", "isVirtual": true },
            "operator": ">",
            "value": 10000
        },
        {
            "type": "leaf",
            "attribute": { "model": "Order", "column": "id", "type": "integer", "isVirtual": true },
            "operator": ">",
            "value": 5
        }
    ]
  },
  "sorts": [
    {
        "attribute": { "model": "Order", "column": "total_revenue", "isVirtual": true },
        "direction": "DESC"
    }
  ]
}
