# Debug SQL & Join Plan Example: AI Integration (Model Context Protocol)

This example demonstrates how an AI Agent can inspect the raw compiled SQL and BFS join plan before executing a report — useful for verifying correctness and explaining the query to the user.

## 1. Defining the MCP Tools

```json
[
  {
    "name": "debug_report_sql",
    "description": "Compile a report AST payload into raw SQL without executing it. Returns the fully-bound SQL string and the parameterized bindings.",
    "parameters": {
      "type": "object",
      "properties": {
        "payload": { "type": "object", "description": "The full ReportRequest AST payload." }
      },
      "required": ["payload"]
    }
  },
  {
    "name": "explain_join_plan",
    "description": "Get the BFS join plan for a report payload. Shows how the engine resolves relationships between models, including which edges were forward-declared and which were reverse-synthesized.",
    "parameters": {
      "type": "object",
      "properties": {
        "payload": { "type": "object", "description": "The full ReportRequest AST payload." }
      },
      "required": ["payload"]
    }
  }
]
```

## 2. Handling the MCP Tool Calls

```javascript
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;
  const headers = { Authorization: "Bearer YOUR_API_TOKEN" };
  const baseUrl = "https://api.yourlaravelapp.com/api/report/debug";

  if (name === "debug_report_sql") {
    const response = await axios.post(`${baseUrl}/sql`, args.payload, { headers });
    return {
      content: [{ type: "text", text: `Compiled SQL:\n\`\`\`sql\n${response.data.sql}\n\`\`\`` }]
    };
  }

  if (name === "explain_join_plan") {
    const response = await axios.post(`${baseUrl}/join-plan`, args.payload, { headers });
    const steps = response.data.steps.map(s => 
      `${s.fromModel} → ${s.toModel} (${s.relationType}, direction: ${s.direction})`
    ).join('\n');
    return {
      content: [{ type: "text", text: `BFS Join Plan:\n${steps}` }]
    };
  }
});
```

---

## Conversation Example

**User**: "Show me total orders by country, but first explain how the database joins work."

**AI Agent**:
1. Constructs payload with `User.country` groupBy and `COUNT(Order.id)` aggregate
2. Calls `explain_join_plan(payload)`
3. Returns:
   > The engine will join these tables:
   > - **User → Order** via `HasMany` (direction: `forward` — this relationship was explicitly declared on the User model)
   > 
   > This means one `LEFT JOIN` will be generated automatically.
4. Calls `debug_report_sql(payload)` 
5. Returns:
   > ```sql
   > SELECT t0.country, COUNT(t1.id) AS total_orders
   > FROM users AS t0
   > LEFT JOIN orders AS t1 ON t0.id = t1.user_id
   > GROUP BY t0.country
   > ORDER BY total_orders DESC
   > LIMIT 5000
   > ```
6. User confirms, agent calls `generate_dynamic_report(payload)` to execute.
