# Register Virtual Attribute Example: React (Hooks)

> **Backend package — `nisalatp/dynamicreportgenerator` (v2.2.0, MIT):** this frontend example drives the
> published Composer package. Install and configure the Laravel backend first, register your reportable models
> in `config/dynamicreportgenerator.php`, and expose JSON endpoints via the `DynamicReport` facade
> (`generate`, `getAvailableModels`, `getModelAttributes`, `saveReport`, `loadAndGenerate`). Payload schema: [AST_REFERENCE.md](reference/ast-reference).

```bash
composer require nisalatp/dynamicreportgenerator
```


This example demonstrates how to build a UI to register a Virtual Attribute (VA) using React.

## The Frontend Implementation

```jsx
import React, { useState } from 'react';

export default function VARegistrar() {
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');
  
  const [form, setForm] = useState({
    name: '',
    model: 'Order',
    type: 'integer',
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
        <select value={form.model} onChange={e => setForm({...form, model: e.target.value})}>
          <option value="Order">Order</option>
        </select>
      </div>
      
      <div>
        <label>Type</label>
        <select value={form.type} onChange={e => setForm({...form, type: e.target.value})}>
          <option value="integer">Integer</option>
        </select>
      </div>

      <div>
        <label>SQL Fragment</label>
        <textarea 
          value={form.sql_fragment}
          onChange={e => setForm({...form, sql_fragment: e.target.value})}
          placeholder="(SELECT COALESCE((SELECT COALESCE(SUM(amount),0) FROM orders WHERE orders.user_id = t0.id),0) FROM orders WHERE orders.user_id = t0.id)"
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


---

## ⚠️ Virtual Attribute SQL fragments (important)

A Virtual Attribute's SQL fragment is injected directly into the `SELECT` clause as a **correlated subquery**, so it **must reference the base table by its query alias `t0`** (the engine compiles the base table as `... as t0`). A bare aggregate such as `SUM(orders.amount)` will not work.

```sql
-- Total spent per user (base model = User, compiled as `users as t0`)
(SELECT COALESCE(SUM(amount), 0) FROM orders WHERE orders.user_id = t0.id)
```

The registration payload is `name`, `base_model`, `sql_fragment`, and an optional `dependencies` array (target models to auto-join before the subquery runs) — there is **no `type` field** on a Virtual Attribute. Server-side this maps to the fluent builder:

```php
use Nisalatp\DynamicReportGenerator\Builders\VirtualAttributeBuilder;

VirtualAttributeBuilder::create('Total Spent')
    ->forBaseModel(\App\Models\User::class)
    ->dependsOn([\App\Models\Order::class])
    ->withSqlFragment('(SELECT COALESCE(SUM(amount),0) FROM orders WHERE orders.user_id = t0.id)')
    ->register();
```
