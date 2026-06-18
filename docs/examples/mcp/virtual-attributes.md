# Register Virtual Attribute Example: AI Integration (Model Context Protocol)

> **Backend package — `nisalatp/dynamicreportgenerator` (v1.0.0, MIT):** this frontend example drives the
> published Composer package. Install and configure the Laravel backend first, register your reportable models
> in `config/dynamicreportgenerator.php`, and expose JSON endpoints via the `DynamicReport` facade
> (`generate`, `getAvailableModels`, `getModelAttributes`, `saveReport`, `loadAndGenerate`). Payload schema: [AST_REFERENCE.md](reference/ast-reference).

```bash
composer require nisalatp/dynamicreportgenerator
```


This example demonstrates how an AI Agent can dynamically register new Virtual Attributes (VAs) based on user requests using the Model Context Protocol (MCP).

## 1. Defining the MCP Tool

```json
{
  "name": "register_virtual_attribute",
  "description": "Register a new Virtual Attribute containing a SQL fragment so it can be used in dynamic reports.",
  "parameters": {
    "type": "object",
    "properties": {
      "name": { "type": "string" },
      "model": { "type": "string", "description": "The Model this applies to, e.g. Order" },
      "type": { "type": "string", "description": "integer, string, float, date" },
      "sql_fragment": { "type": "string", "description": "The raw SQL snippet, e.g. (SELECT COALESCE(SUM(amount),0) FROM orders WHERE orders.user_id = t0.id)" }
    },
    "required": ["name", "model", "type", "sql_fragment"]
  }
}
```

## 2. Handling the MCP Tool Call

```javascript
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  if (request.params.name === "register_virtual_attribute") {
    const { name, model, type, sql_fragment } = request.params.arguments;

    try {
      await axios.post("/api/va/register", {
        name,
        model,
        type,
        sql_fragment
      }, {
        headers: { Authorization: "Bearer YOUR_ADMIN_TOKEN" }
      });

      return {
        content: [{ type: "text", text: `Successfully registered Virtual Attribute: ${name}` }]
      };
    } catch (error) {
      return {
        content: [{ type: "text", text: `Error registering VA: ${error.message}` }],
        isError: true
      };
    }
  }
});
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst asks the AI assistant: *"I want to analyze active user purchasing behavior across Electronics and Software. I need to see the total revenue and order count, grouped by the user's country and the product's category. I only want to see groupings that generated more than $10,000 in revenue and had more than 5 orders. If you need to register 'total_revenue' and 'total_orders' as virtual attributes first, go ahead and do it."*

**Models Used**: `User`, `Order`, `Product`.

The LLM parses the intent and realizes it needs virtual attributes to run the HAVING clauses. It invokes `register_virtual_attribute` twice for `total_revenue` and `total_orders`. 

Once registered, it invokes the `generate_dynamic_report` tool with the following AST, referencing the VAs using `"isVirtual": true`:

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
