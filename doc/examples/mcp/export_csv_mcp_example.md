# CSV Export Example: AI Integration (Model Context Protocol)

This example demonstrates how an AI Agent can trigger a CSV export of a dynamically generated report via the MCP tool interface.

## 1. Defining the MCP Tool

```json
{
  "name": "export_report_csv",
  "description": "Export the results of a dynamic report AST payload as a CSV file. The backend streams the results using cursor-based iteration for O(1) memory usage.",
  "parameters": {
    "type": "object",
    "properties": {
      "payload": {
        "type": "object",
        "description": "The full ReportRequest AST payload (see AST_REFERENCE.md)."
      },
      "filename": {
        "dataType": "string",
        "description": "The desired filename for the CSV download (e.g., 'revenue_report.csv')."
      }
    },
    "required": ["payload", "filename"]
  }
}
```

## 2. Handling the MCP Tool Call

```javascript
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  if (request.params.name === "export_report_csv") {
    const { payload, filename } = request.params.arguments;

    const response = await axios.post(
      "https://api.yourlaravelapp.com/api/report/export-csv",
      { payload, filename },
      { 
        headers: { Authorization: "Bearer YOUR_API_TOKEN" },
        responseType: 'arraybuffer'  // Receive the CSV as binary
      }
    );

    // Save to a local temp file for the LLM to reference
    const fs = require('fs');
    const path = `/tmp/${filename}`;
    fs.writeFileSync(path, response.data);

    return {
      content: [{ 
        type: "text", 
        text: `CSV exported successfully to ${path} (${response.data.length} bytes)` 
      }]
    };
  }
});
```

---

## Conversation Example

**User**: "Export total revenue by country as a CSV file."

**AI Agent**:
1. Calls `get_available_models()` → `["User", "Order"]`
2. Calls `get_model_attributes("User")` → `["id", "name", "country", ...]`
3. Constructs the AST payload with `SUM(Order.amount)` grouped by `User.country`
4. Calls `export_report_csv(payload, "revenue_by_country.csv")`
5. Returns: *"I've exported the revenue-by-country report as `revenue_by_country.csv`. The file contains 47 rows."*
