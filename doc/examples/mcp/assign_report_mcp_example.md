# Assign/Unassign Report Example: AI Integration (Machine Context Protocol)

This example demonstrates how an AI Agent can manage **Report Access Control** — granting or revoking a user's permission to view and execute a saved report — using the Machine Context Protocol (MCP).

> **Background**: When a report is saved via `save_dynamic_report`, it is accessible to anyone who can list the report library by default. To restrict access, the agent must explicitly assign users to the report using the `assign_report` tool. Only the **report owner** (the user who created it) and **explicitly assigned users** can access it when queried via `get_my_reports`.

---

## 1. Defining the MCP Tools

### `assign_report` — Grant access

```json
{
  "name": "assign_report",
  "description": "Grant a specific user permission to view and execute a saved report. The report owner always retains access. Uses the dynamic_report_user pivot table internally.",
  "parameters": {
    "type": "object",
    "properties": {
      "report_id": { "dataType": "integer", "description": "The SavedReport ID to grant access to" },
      "user_id": { "dataType": "integer", "description": "The User ID who should be granted access" }
    },
    "required": ["report_id", "user_id"]
  }
}
```

### `unassign_report` — Revoke access

```json
{
  "name": "unassign_report",
  "description": "Revoke a specific user's permission to view and execute a saved report. Does NOT affect the report owner.",
  "parameters": {
    "type": "object",
    "properties": {
      "report_id": { "dataType": "integer", "description": "The SavedReport ID" },
      "user_id": { "dataType": "integer", "description": "The User ID to revoke access from" }
    },
    "required": ["report_id", "user_id"]
  }
}
```

### `get_my_reports` — List accessible reports

```json
{
  "name": "get_my_reports",
  "description": "List only the saved reports that the current user owns or has been explicitly assigned access to.",
  "parameters": {
    "type": "object",
    "properties": {}
  }
}
```

---

## 2. Handling the MCP Tool Calls

```javascript
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const API_BASE = "https://api.yourlaravelapp.com/ntp_drg";

  if (request.params.name === "assign_report") {
    const { report_id, user_id } = request.params.arguments;
    try {
      await axios.post(`${API_BASE}/reports/${report_id}/assign`, { user_id }, {
        headers: { Authorization: "Bearer YOUR_ADMIN_TOKEN" }
      });
      return {
        content: [{ type: "text", text: `Granted User ${user_id} access to Report ${report_id}` }]
      };
    } catch (error) {
      return { content: [{ type: "text", text: `Error: ${error.message}` }], isError: true };
    }
  }

  if (request.params.name === "unassign_report") {
    const { report_id, user_id } = request.params.arguments;
    try {
      await axios.post(`${API_BASE}/reports/${report_id}/unassign`, { user_id }, {
        headers: { Authorization: "Bearer YOUR_ADMIN_TOKEN" }
      });
      return {
        content: [{ type: "text", text: `Revoked User ${user_id} access from Report ${report_id}` }]
      };
    } catch (error) {
      return { content: [{ type: "text", text: `Error: ${error.message}` }], isError: true };
    }
  }

  if (request.params.name === "get_my_reports") {
    try {
      const response = await axios.get(`${API_BASE}/my-reports`, {
        headers: { Authorization: "Bearer YOUR_API_TOKEN" }
      });
      return {
        content: [{ type: "text", text: JSON.stringify(response.data, null, 2) }]
      };
    } catch (error) {
      return { content: [{ type: "text", text: `Error: ${error.message}` }], isError: true };
    }
  }
});
```

---

## 3. McpAgentController Integration

If you are using the built-in `McpAgentController` pattern (DeepSeek/OpenAI function-calling orchestrator), add these tool definitions to `getToolDefinitions()`:

```php
// In getToolDefinitions() array:
[
    'type' => 'function',
    'function' => [
        'name' => 'assign_report',
        'description' => 'Grant a user permission to view and execute a saved report. The report owner always retains access.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'report_id' => ['type' => 'integer', 'description' => 'The SavedReport ID'],
                'user_id' => ['type' => 'integer', 'description' => 'The User ID to grant access to'],
            ],
            'required' => ['report_id', 'user_id'],
        ],
    ],
],
[
    'type' => 'function',
    'function' => [
        'name' => 'unassign_report',
        'description' => 'Revoke a user\'s permission to view and execute a saved report. Does NOT affect the report owner.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'report_id' => ['type' => 'integer', 'description' => 'The SavedReport ID'],
                'user_id' => ['type' => 'integer', 'description' => 'The User ID to revoke access from'],
            ],
            'required' => ['report_id', 'user_id'],
        ],
    ],
],
[
    'type' => 'function',
    'function' => [
        'name' => 'get_my_reports',
        'description' => 'List only the saved reports that the current user owns or has been explicitly assigned access to.',
        'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
    ],
],
```

And add these cases to the `executeTool()` switch block:

```php
case 'assign_report':
    DynamicReport::assignReport($args['report_id'], $args['user_id'], auth()->id());
    return ['status' => 'assigned', 'report_id' => $args['report_id'], 'user_id' => $args['user_id']];

case 'unassign_report':
    DynamicReport::unassignReport($args['report_id'], $args['user_id'], auth()->id());
    return ['status' => 'unassigned', 'report_id' => $args['report_id'], 'user_id' => $args['user_id']];

case 'get_my_reports':
    return ['reports' => DynamicReport::getAssignedReports(auth()->id())->toArray()];
```

---

## 4. Backend API Reference

| Method | Endpoint | Payload | Response | Engine Method |
|--------|----------|---------|----------|---------------|
| POST | `/ntp_drg/reports/{id}/assign` | `{user_id}` | `{message}` | `DynamicReport::assignReport()` |
| POST | `/ntp_drg/reports/{id}/unassign` | `{user_id}` | `{message}` | `DynamicReport::unassignReport()` |
| GET | `/ntp_drg/my-reports` | — | `SavedReport[]` | `DynamicReport::getAssignedReports()` |

### Database Schema

The access control layer uses a pivot table `dynamic_report_user`:

| Column | Type | Description |
|--------|------|-------------|
| `id` | unsignedBigInteger | Primary key |
| `saved_report_id` | unsignedBigInteger | FK → `dynamic_saved_reports.id` (CASCADE) |
| `user_id` | unsignedBigInteger | The user who is granted access |
| `created_at` | timestamp | When access was granted |
| `updated_at` | timestamp | Last modified |

**Unique constraint**: `(saved_report_id, user_id)` — prevents duplicate assignments.

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst asks the AI assistant:
> *"Save the report we just built as 'Q3 Regional Sales'. Then restrict it so only Alice (User 3) and Bob (User 7) can see it. Everyone else should be locked out."*

**Agent Workflow**:

1. Agent calls `save_dynamic_report` with the AST payload → returns `{ id: 42, name: "Q3 Regional Sales" }`
2. Agent calls `assign_report` with `{ report_id: 42, user_id: 3 }` → grants Alice access
3. Agent calls `assign_report` with `{ report_id: 42, user_id: 7 }` → grants Bob access
4. Agent responds: *"Done. The 'Q3 Regional Sales' report (ID 42) is saved and restricted to you, Alice, and Bob."*

Now when other users call `get_my_reports`, Report 42 will **not** appear in their list. Only the owner and assigned users (Alice and Bob) can see and execute it via `run_saved_report`.

> **Note**: The report owner (the authenticated user who called `save_dynamic_report`) always retains full access regardless of assignment state. `unassign_report` cannot remove the owner.
