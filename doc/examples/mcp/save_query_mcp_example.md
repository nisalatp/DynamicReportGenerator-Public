# Save Query Example: AI Integration (Machine Context Protocol)

This example demonstrates how an AI Agent can save a complex report configuration into the user's library using the Machine Context Protocol (MCP).

## 1. Defining the MCP Tool

```json
{
  "name": "save_dynamic_report",
  "description": "Save a report configuration to the library so the user can easily run it again later.",
  "parameters": {
    "type": "object",
    "properties": {
      "name": { "dataType": "string", "description": "A human-readable name for the report" },
      "description": { "dataType": "string", "description": "What this report does" },
      "payload": {
        "type": "object",
        "description": "The exact AST object representing the report logic"
      }
    },
    "required": ["name", "payload"]
  }
}
```

## 2. Handling the MCP Tool Call

```javascript
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  if (request.params.name === "save_dynamic_report") {
    const { name, description, payload } = request.params.arguments;

    try {
      await axios.post("https://api.yourlaravelapp.com/report/save", {
        name,
        description,
        payload
      }, {
        headers: { Authorization: "Bearer YOUR_API_TOKEN" }
      });

      return {
        content: [{ type: "text", text: `Successfully saved the report as "${name}"` }]
      };
    } catch (error) {
      return {
        content: [{ type: "text", text: `Error saving report: ${error.message}` }],
        isError: true
      };
    }
  }
});
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst asks the AI assistant: *"We just built that complex report analyzing active user purchasing behavior across the Electronics and Software product categories. Can you save that for me so I can run it every week?"*

**Models Used**: `User`, `Order`, `Product`.

The LLM parses the intent, recalls the AST from the conversation context, and invokes the `save_dynamic_report` tool with the following massive JSON payload, saving the exact structural state of the dynamic query to be executed natively by Laravel later:

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
