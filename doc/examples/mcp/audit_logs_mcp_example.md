# Audit Logs Example: AI Integration (Model Context Protocol)

This example demonstrates how an AI Agent can query the audit log system for compliance monitoring and troubleshooting.

## 1. Defining the MCP Tool

```json
{
  "name": "get_audit_logs",
  "description": "Query the dynamic report audit logs. Supports filtering by action type (created, updated, executed, assigned, unassigned, deleted, error), report ID, and user ID.",
  "parameters": {
    "type": "object",
    "properties": {
      "action": { 
        "dataType": "string", 
        "description": "Filter by action type.",
        "enum": ["created", "updated", "executed", "assigned", "unassigned", "deleted", "error"]
      },
      "report_id": { "dataType": "integer", "description": "Filter by specific saved report ID." },
      "user_id": { "dataType": "integer", "description": "Filter by specific user ID." }
    }
  }
}
```

## 2. Handling the MCP Tool Call

```javascript
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  if (request.params.name === "get_audit_logs") {
    const args = request.params.arguments;
    const params = new URLSearchParams();
    if (args.action) params.append('action', args.action);
    if (args.report_id) params.append('report_id', args.report_id);
    if (args.user_id) params.append('user_id', args.user_id);

    const response = await axios.get(
      `https://api.yourlaravelapp.com/api/admin/logs?${params.toString()}`,
      { headers: { Authorization: "Bearer YOUR_API_TOKEN" } }
    );

    // Format the logs into a readable summary for the LLM
    const summary = response.data.map(log => 
      `[${log.created_at}] ${log.action.toUpperCase()} — Report: ${log.report_name || 'N/A'}, User: ${log.user_name || 'System'}${log.details ? ' — ' + log.details : ''}`
    ).join('\n');

    return {
      content: [{ type: "text", text: summary || "No audit logs found." }]
    };
  }
});
```

---

## Conversation Examples

**User**: "Were there any report errors today?"

**AI Agent**:
1. Calls `get_audit_logs(action: "error")`
2. Returns:
   > Found 2 errors:
   > - `[2026-05-13 14:32:10] ERROR — Report: "Sales by Region", User: analyst@company.com — ReportMakerException: Model 'Payment' is restricted`
   > - `[2026-05-13 09:15:42] ERROR — Report: "Deep Filter Test", User: admin@company.com — ReportMakerException: Filter nesting depth (5) exceeds maximum allowed (3)`

**User**: "Show me the complete history for report #42."

**AI Agent**:
1. Calls `get_audit_logs(report_id: 42)`
2. Returns:
   > Report #42 lifecycle:
   > - `[2026-05-10 08:00:00] CREATED — Report: "Q2 Analysis", User: admin@company.com`
   > - `[2026-05-10 08:15:00] ASSIGNED — Report: "Q2 Analysis", User: admin@company.com — To User #5`
   > - `[2026-05-11 10:30:00] EXECUTED — Report: "Q2 Analysis", User: analyst@company.com`
   > - `[2026-05-12 09:00:00] UPDATED — Report: "Q2 Analysis (Revised)", User: admin@company.com`
   > - `[2026-05-13 14:00:00] EXECUTED — Report: "Q2 Analysis (Revised)", User: analyst@company.com`
