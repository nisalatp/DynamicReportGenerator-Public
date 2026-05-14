# Register Virtual Attribute Example: React (Hooks)

This example demonstrates how to build a UI to register a Virtual Attribute (VA) using React.

## The Frontend Implementation

```jsx
import React, { useState } from 'react';

export default function VARegistrar() {
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');
  
  const [form, setForm] = useState({
    name: '',
    base_model: 'Order',
    return_type: 'integer',
    sql_fragment: ''
  });

  const registerVA = async () => {
    setLoading(true);
    setMessage('');
    
    try {
      const response = await fetch('/api/va/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(form)
      });
      
      if (response.ok) {
        setMessage('Virtual Attribute registered successfully!');
        setForm(prev => ({ ...prev, name: '', sql_fragment: '' }));
      }
    } catch (error) {
      console.error("Failed to register VA", error);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="va-registrar">
      <h2>Register Virtual Attribute</h2>
      
      <div>
        <label>Name</label>
        <input 
          type="text" 
          value={form.name}
          onChange={e => setForm({...form, name: e.target.value})}
          placeholder="e.g., total_revenue"
        />
      </div>
      
      <div>
        <label>Model</label>
        <select value={form.base_model} onChange={e => setForm({...form, base_model: e.target.value})}>
          <option value="Order">Order</option>
        </select>
      </div>
      
      <div>
        <label>Return Type</label>
        <select value={form.return_type} onChange={e => setForm({...form, return_type: e.target.value})}>
          <option value="string">String</option>
          <option value="integer">Integer</option>
          <option value="boolean">Boolean</option>
          <option value="date">Date</option>
        </select>
      </div>

      <div>
        <label>SQL Fragment</label>
        <textarea 
          value={form.sql_fragment}
          onChange={e => setForm({...form, sql_fragment: e.target.value})}
          placeholder="SUM(orders.amount)"
        />
      </div>

      <button onClick={registerVA} disabled={loading || !form.name || !form.sql_fragment}>
        {loading ? 'Registering...' : 'Register Attribute'}
      </button>

      {message && <p style={{ color: 'green' }}>{message}</p>}
    </div>
  );
}
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst wants to analyze active user purchasing behavior across specific product categories. They want to see the total revenue and order count, grouped by the user's country and the product's category. They only want to see groupings that generated more than $10,000 in revenue and had more than 5 orders. 

Before they can build this AST, an administrator must register `total_revenue` (the sum of order amounts) and `total_orders` (the count of orders) as Virtual Attributes so they can be referenced in the AST's `outerFilters` (HAVING clauses).

**Models Used**: `User`, `Order`, `Product`.

The admin uses the UI above to register the two VAs. Once registered, the user can build the following AST, referencing the VAs using `"isVirtual": true`:

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
  }
}
```
