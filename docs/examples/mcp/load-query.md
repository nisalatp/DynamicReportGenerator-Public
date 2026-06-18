# Load Query Example: AI Integration (Model Context Protocol)

> **Backend package — `nisalatp/dynamicreportgenerator` (v1.0.0, MIT):** this frontend example drives the
> published Composer package. Install and configure the Laravel backend first, register your reportable models
> in `config/dynamicreportgenerator.php`, and expose JSON endpoints via the `DynamicReport` facade
> (`generate`, `getAvailableModels`, `getModelAttributes`, `saveReport`, `loadAndGenerate`). Payload schema: [AST_REFERENCE.md](reference/ast-reference).

```bash
composer require nisalatp/dynamicreportgenerator
```


This example demonstrates how an AI Agent can browse and load a saved report's configuration using the Model Context Protocol (MCP).

## 1. Defining the MCP Tool

```json
{
  "name": "get_saved_report_config",
  "description": "Fetch the exact AST configuration payload of a saved report by its ID so you can modify it or understand what it does.",
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
  if (request.params.name === "get_saved_report_config") {
    const { reportId } = request.params.arguments;

    try {
      const response = await axios.get(`/api/report/saved/${reportId}`, {
        headers: { Authorization: "Bearer YOUR_API_TOKEN" }
      });

      // We return the raw AST payload back to the LLM
      return {
        content: [{ type: "text", text: response.data.payload }]
      };
    } catch (error) {
      return {
        content: [{ type: "text", text: `Error fetching report: ${error.message}` }],
        isError: true
      };
    }
  }
});
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst asks the AI assistant: *"Load up the High Value Segment Analysis report. I want to change it so it only looks at Software, not Electronics."*

**Models Used**: `User`, `Order`, `Product`.

The LLM invokes the `get_saved_report_config` tool to fetch the report. The backend returns the massive AST payload. The LLM reads this JSON, understands the recursive `FilterGroup` structure in the `innerFilters`, and knows exactly how to remove the "Electronics" node before generating a new report:

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
