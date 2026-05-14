# Update & Delete Report Example: AI Integration (Model Context Protocol)

This example demonstrates how an AI Agent can manage the lifecycle of saved reports — updating their metadata and deleting them when requested.

## 1. Defining the MCP Tools

```json
[
  {
    "name": "update_saved_report",
    "description": "Update the name and/or description of a saved report. Optionally update the AST payload.",
    "parameters": {
      "type": "object",
      "properties": {
        "report_id": { "dataType": "integer", "description": "The ID of the saved report to update." },
        "name": { "dataType": "string", "description": "New name for the report." },
        "description": { "dataType": "string", "description": "New description." }
      },
      "required": ["report_id"]
    }
  },
  {
    "name": "delete_saved_report",
    "description": "Permanently delete a saved report and all its assignments. This action is logged.",
    "parameters": {
      "type": "object",
      "properties": {
        "report_id": { "dataType": "integer", "description": "The ID of the saved report to delete." }
      },
      "required": ["report_id"]
    }
  }
]
```

## 2. Handling the MCP Tool Calls

```javascript
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;
  const headers = { Authorization: "Bearer YOUR_API_TOKEN" };
  const baseUrl = "https://api.yourlaravelapp.com/api/report/saved";

  if (name === "update_saved_report") {
    const body = {};
    if (args.name) body.name = args.name;
    if (args.description) body.description = args.description;

    const response = await axios.put(`${baseUrl}/${args.report_id}`, body, { headers });
    return {
      content: [{ type: "text", text: `Report #${args.report_id} updated: ${JSON.stringify(response.data)}` }]
    };
  }

  if (name === "delete_saved_report") {
    await axios.delete(`${baseUrl}/${args.report_id}`, { headers });
    return {
      content: [{ type: "text", text: `Report #${args.report_id} has been permanently deleted.` }]
    };
  }
});
```

---

## Conversation Example

**User**: "Rename report #12 to 'Q2 Revenue by Region' and add a description."

**AI Agent**:
1. Calls `update_saved_report(report_id: 12, name: "Q2 Revenue by Region", description: "Revenue breakdown by geographic region for Q2 2026")`
2. Returns: *"Done! Report #12 has been renamed to 'Q2 Revenue by Region'."*

**User**: "Delete report #7, it's outdated."

**AI Agent**:
1. Calls `delete_saved_report(report_id: 7)`
2. Returns: *"Report #7 has been permanently deleted. The deletion has been recorded in the audit log."*
