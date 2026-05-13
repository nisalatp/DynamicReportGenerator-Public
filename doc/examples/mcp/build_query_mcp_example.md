# Build Query Example: AI Integration (Machine Context Protocol)

This example demonstrates how to integrate the Dynamic Report Generator with an AI Agent using the **Machine Context Protocol (MCP)**. 

> [!IMPORTANT]
> Please refer to the [AST Reference Guide](../AST_REFERENCE.md) for the complete, definitive constraints and validation rules of the payload structure.

## 1. Defining the Complete MCP Tool

You define an MCP Tool that the AI can call. The schema matches the `ReportRequest` DTO exactly, explicitly defining the complex, recursive filtering structures, `GroupBy`, and `Aggregate` capabilities.

```json
{
  "name": "generate_dynamic_report",
  "description": "Generate a data report. You can use complex nested filters (innerFilters for WHERE, outerFilters for HAVING) and specify aggregations.",
  "parameters": {
    "type": "object",
    "properties": {
      "baseModel": { "type": "string" },
      "targetModels": { "type": "array", "items": { "type": "string" } },
      "selectedAttributes": {
        "type": "array",
        "items": {
          "type": "object",
          "properties": {
            "modelClass": { "type": "string" },
            "column": { "type": "string" },
            "type": { "type": "string" }
          }
        }
      },
      "innerFilters": { "type": "object", "description": "A FilterGroup or FilterLeaf for the WHERE clause" },
      "groupBys": { "type": "array", "items": { "type": "object" } },
      "aggregates": { "type": "array", "items": { "type": "object" } },
      "outerFilters": { "type": "object", "description": "A FilterGroup or FilterLeaf for the HAVING clause" }
    },
    "required": ["baseModel"]
  }
}
```

## 2. Handling the MCP Tool Call

```javascript
import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import axios from "axios";

const server = new Server(
  { name: "reporting-server", version: "1.0.0" },
  { capabilities: { tools: {} } }
);

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  if (request.params.name === "generate_dynamic_report") {
    const payload = request.params.arguments; // The fully formed AST

    try {
      const response = await axios.post("https://api.yourlaravelapp.com/report/generate", payload, {
        headers: { Authorization: "Bearer YOUR_API_TOKEN" }
      });

      return {
        content: [{ type: "text", text: JSON.stringify(response.data.data) }]
      };
    } catch (error) {
      return {
        content: [{ type: "text", text: `Error generating report: ${error.message}` }],
        isError: true
      };
    }
  }
  throw new Error("Tool not found");
});

const transport = new StdioServerTransport();
await server.connect(transport);
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst asks the AI assistant: *"Give me a breakdown of our active users' purchasing behavior across the Electronics and Software product categories. I want to see the total revenue and order count, grouped by the user's country and the product's category. Only show me groupings that generated more than $10,000 in revenue and had more than 5 orders."*

**Models Used**: `User`, `Order`, `Product`.

The LLM parses this highly complex intent. It knows the schema. It natively generates the following massive JSON AST payload without writing a single line of SQL hallucination:

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
  },
  "sorts": [
    {
        "attribute": { "modelClass": "Order", "column": "total_revenue", "isVirtual": true },
        "direction": "DESC"
    }
  ]
}
```
