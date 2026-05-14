# Schema Discovery Example: AI Integration (Machine Context Protocol)

This example demonstrates how an AI Agent can dynamically query the Report Generator to understand the database schema, available attributes, and relationship paths before it generates an AST.

## 1. Defining the MCP Tools

We expose three tools to the LLM so it can traverse the graph automatically.

```json
[
  {
    "name": "get_available_models",
    "description": "Get a list of all queryable base models in the system.",
    "parameters": { "type": "object", "properties": {} }
  },
  {
    "name": "get_model_attributes",
    "description": "Get all queryable columns (both physical and virtual) for a specific model.",
    "parameters": {
      "type": "object",
      "properties": { "modelClass": { "type": "string" } },
      "required": ["model"]
    }
  },
  {
    "name": "get_model_relationships",
    "description": "Get all models that this model can join to, and the type of relationship. Each edge includes a 'direction' field ('forward' or 'reverse') indicating how the relationship was discovered.",
    "parameters": {
      "type": "object",
      "properties": { "modelClass": { "type": "string" } },
      "required": ["model"]
    }
  },
  {
    "name": "get_max_filter_depth",
    "description": "Get the maximum allowed nesting depth for AND/OR filter groups. The engine will reject payloads exceeding this depth.",
    "parameters": { "type": "object", "properties": {} }
  }
]
```

## 2. Handling the MCP Tool Calls

```javascript
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const name = request.params.name;
  const args = request.params.arguments;
  
  const baseUrl = "https://api.yourlaravelapp.com/api/schema";
  const headers = { Authorization: "Bearer YOUR_API_TOKEN" };

  try {
    let url = "";
    if (name === "get_available_models") url = `${baseUrl}/models`;
    else if (name === "get_model_attributes") url = `${baseUrl}/models/${args.model}/attributes`;
    else if (name === "get_model_relationships") url = `${baseUrl}/models/${args.model}/relationships`;
    else if (name === "get_max_filter_depth") url = `${baseUrl}/config/max-filter-depth`;

    const response = await axios.get(url, { headers });

    // Return the JSON schema discovery payload directly to the LLM
    return {
      content: [{ type: "text", text: JSON.stringify(response.data, null, 2) }]
    };
  } catch (error) {
    return {
      content: [{ type: "text", text: `Error: ${error.message}` }],
      isError: true
    };
  }
});
```

---

## Output Example Context

When the user asks: *"Build me a report showing order revenue by user country"*.

The AI Agent doesn't know the schema yet. It executes the following sequence:

1. **LLM Calls:** `get_available_models()`
   - **System Returns:** `["User", "Order", "Product"]`
2. **LLM Calls:** `get_max_filter_depth()`
   - **System Returns:** `{ "max_filter_depth": 3 }` (The LLM now knows the nesting constraint for filters).
3. **LLM Calls:** `get_model_attributes("Order")`
   - **System Returns:** `["id", "amount", "user_id", "va:total_revenue"]` (The LLM now knows it can SUM the `amount` column).
4. **LLM Calls:** `get_model_relationships("Order")`
   - **System Returns:** `{"User": { "type": "BelongsTo", "methodName": "user", "direction": "forward" }}` (The LLM now knows `Order` connects to `User`, and this is a forward-declared edge).
5. **LLM Calls:** `get_model_attributes("User")`
   - **System Returns:** `["id", "name", "country"]` (The LLM now knows the `country` column exists).

The AI Agent now has perfect context to build the correct AST payload and invoke the `generate_dynamic_report` tool!
