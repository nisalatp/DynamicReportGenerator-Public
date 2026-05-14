# Register Virtual Attribute Example: AI Integration (Machine Context Protocol)

This example demonstrates how an AI Agent can dynamically register new Virtual Attributes (VAs) based on user requests using the Machine Context Protocol (MCP).

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
      "return_type": { "type": "string", "description": "integer, string, float, date" },
      "sql_fragment": { "type": "string", "description": "The raw SQL snippet, e.g. SUM({THIS}.amount). CRITICAL: Always prefix physical columns with {THIS}. to prevent ambiguous column errors." },
      "dependencies": { "type": "array", "items": { "type": "string" }, "description": "Array of target model names this requires a JOIN to calculate. DO NOT list column names here. Leave empty [] if it only uses the base model." }
    },
    "required": ["name", "model", "return_type", "sql_fragment"]
  }
}
```

## 2. Handling the MCP Tool Call

```javascript
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  if (request.params.name === "register_virtual_attribute") {
    const { name, model, return_type, sql_fragment, dependencies } = request.params.arguments;

    try {
      await axios.post("https://api.yourlaravelapp.com/va/register", {
        name,
        base_model: model,
        return_type,
        sql_fragment,
        dependencies: dependencies || []
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
