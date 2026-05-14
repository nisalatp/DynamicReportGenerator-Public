# Model Restriction Example: AI Integration (Model Context Protocol)

This example demonstrates how an AI Agent can manage whole-model access restrictions — completely blocking models from being available in the report builder.

## 1. Defining the MCP Tools

```json
[
  {
    "name": "get_restricted_models",
    "description": "Get a list of all models currently restricted from reporting.",
    "parameters": { "type": "object", "properties": {} }
  },
  {
    "name": "restrict_model",
    "description": "Block a model from being available in the report builder. This removes it from schema discovery and prevents any reports from querying it.",
    "parameters": {
      "type": "object",
      "properties": {
        "model_class": { "type": "string", "description": "The fully qualified model class name." }
      },
      "required": ["model_class"]
    }
  },
  {
    "name": "unrestrict_model",
    "description": "Remove a model restriction, making it available for reporting again.",
    "parameters": {
      "type": "object",
      "properties": {
        "model_class": { "type": "string", "description": "The fully qualified model class name." }
      },
      "required": ["model_class"]
    }
  }
]
```

## 2. Handling the MCP Tool Calls

```javascript
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;
  const headers = { Authorization: "Bearer YOUR_API_TOKEN" };
  const baseUrl = "https://api.yourlaravelapp.com/api/admin/models";

  if (name === "get_restricted_models") {
    const response = await axios.get(`${baseUrl}/restricted`, { headers });
    return {
      content: [{ type: "text", text: JSON.stringify(response.data, null, 2) }]
    };
  }

  if (name === "restrict_model") {
    await axios.post(`${baseUrl}/restrict`, { model_class: args.model_class }, { headers });
    return {
      content: [{ type: "text", text: `Model ${args.model_class} has been restricted from reporting.` }]
    };
  }

  if (name === "unrestrict_model") {
    await axios.post(`${baseUrl}/unrestrict`, { model_class: args.model_class }, { headers });
    return {
      content: [{ type: "text", text: `Model ${args.model_class} is now available for reporting again.` }]
    };
  }
});
```

---

## Conversation Example

**User**: "Hide the Payment model from the report builder — analysts shouldn't see financial data."

**AI Agent**:
1. Calls `restrict_model(model_class: "App\\Models\\Payment")`
2. Returns: *"Done. The Payment model has been restricted. Analysts will no longer see it in the model selector, and any saved reports that depend on it will fail with a ReportMakerException."*

**User**: "Actually, unrestrict it — the finance team needs it."

**AI Agent**:
1. Calls `unrestrict_model(model_class: "App\\Models\\Payment")`
2. Returns: *"The Payment model is now available for reporting again. The BFS graph cache has been refreshed."*
