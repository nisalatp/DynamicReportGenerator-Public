# Run Saved Query Example: AI Integration (Model Context Protocol)

> **Backend package — `nisalatp/dynamicreportgenerator` (v2.2.0, MIT):** this frontend example drives the
> published Composer package. Install and configure the Laravel backend first, register your reportable models
> in `config/dynamicreportgenerator.php`, and expose JSON endpoints via the `DynamicReport` facade
> (`generate`, `getAvailableModels`, `getModelAttributes`, `saveReport`, `loadAndGenerate`). Payload schema: [AST_REFERENCE.md](reference/ast-reference).

```bash
composer require nisalatp/dynamicreportgenerator
```


This example demonstrates how an AI Agent can execute a saved report configuration using the Model Context Protocol (MCP).

## 1. Defining the MCP Tool

```json
{
  "name": "run_saved_report",
  "description": "Execute a saved report by its ID and get the latest data results. Use this when the user asks to run a report from their library.",
  "parameters": {
    "type": "object",
    "properties": {
      "reportId": { "type": "integer" }
    },
    "required": ["reportId"]
  }
}
```

## 2. Handling the MCP Tool Call

```javascript
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  if (request.params.name === "run_saved_report") {
    const { reportId } = request.params.arguments;

    try {
      const response = await axios.post(`/api/report/saved/${reportId}/execute`, {}, {
        headers: { Authorization: "Bearer YOUR_API_TOKEN" }
      });

      // We return the actual data results back to the LLM to summarize
      return {
        content: [{ type: "text", text: JSON.stringify(response.data.data) }]
      };
    } catch (error) {
      return {
        content: [{ type: "text", text: `Error running report: ${error.message}` }],
        isError: true
      };
    }
  }
});
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst asks the AI assistant: *"Run the High Value Segment Analysis report and tell me which country had the most revenue."*

**Models Used**: `User`, `Order`, `Product`.

The LLM invokes the `run_saved_report` tool, passing only the integer ID. The Laravel backend implicitly hydrates this massive AST payload. It then validates it, compiles it into SQL, executes it, and returns the data array directly to the LLM so it can answer the user's question:

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
