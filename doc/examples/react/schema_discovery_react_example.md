# Schema Discovery Example: React (Hooks)

This example demonstrates how to build a dynamic schema explorer in React. It queries the Report Generator backend for models, attributes (including Virtual Attributes), and relationships.

## The Frontend Implementation

```jsx
import React, { useState, useEffect } from 'react';

export default function SchemaExplorer() {
  const [availableModels, setAvailableModels] = useState([]);
  const [selectedModel, setSelectedModel] = useState('');
  
  const [attributes, setAttributes] = useState([]);
  const [relationships, setRelationships] = useState({});
  const [loading, setLoading] = useState(false);

  // Fetch models on mount
  useEffect(() => {
    const fetchModels = async () => {
      try {
        const res = await fetch('/api/schema/models');
        const data = await res.json();
        setAvailableModels(data);
      } catch (error) {
        console.error("Failed to load models", error);
      }
    };
    fetchModels();
  }, []);

  // Fetch attributes and relationships when selectedModel changes
  useEffect(() => {
    const fetchDetails = async () => {
      if (!selectedModel) {
        setAttributes([]);
        setRelationships({});
        return;
      }

      setLoading(true);
      try {
        const [attrRes, relRes] = await Promise.all([
          fetch(`/api/schema/models/${selectedModel}/attributes`),
          fetch(`/api/schema/models/${selectedModel}/relationships`)
        ]);

        setAttributes(await attrRes.json());
        setRelationships(await relRes.json());
      } catch (error) {
        console.error("Failed to fetch schema details", error);
      } finally {
        setLoading(false);
      }
    };

    fetchDetails();
  }, [selectedModel]);

  return (
    <div className="schema-explorer">
      <h2>Schema Explorer</h2>

      <div className="form-group">
        <label>Select Base Model: </label>
        <select value={selectedModel} onChange={(e) => setSelectedModel(e.target.value)}>
          <option value="">-- Choose a Model --</option>
          {availableModels.map(model => (
            <option key={model} value={model}>{model}</option>
          ))}
        </select>
      </div>

      {loading && <p>Loading schema details...</p>}

      {!loading && selectedModel && (
        <div className="schema-details">
          {attributes.length > 0 && (
            <div className="section">
              <h3>Available Attributes</h3>
              <ul>
                {attributes.map(attr => (
                  <li key={attr}>
                    {attr}
                    {attr.startsWith('va:') && (
                      <span style={{ color: 'blue', fontSize: '0.8em', marginLeft: '8px' }}>
                        (Virtual)
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {Object.keys(relationships).length > 0 && (
            <div className="section">
              <h3>Discoverable Relationships</h3>
              <ul>
                {Object.entries(relationships).map(([targetModel, rel]) => (
                  <li key={targetModel}>
                    <strong>{targetModel}</strong> ({rel.type} via <code>{rel.methodName}()</code>)
                    <span className={`badge ${rel.direction === 'reverse' ? 'reverse' : 'forward'}`}>
                      {rel.direction}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
```

---

## Output Example Context

When the user selects `Order` from the dropdown, the frontend might render the following:

**Available Attributes**
- `id`
- `user_id`
- `amount`
- `created_at`
- `updated_at`
- `va:total_revenue` <span style="color: blue;">(Virtual)</span>

**Discoverable Relationships**
- `User` (BelongsTo via `user()`) — direction: `forward`
- `Product` (BelongsToMany via `products()`) — direction: `forward`
- `OrderItem` (HasMany via `orderItems()`) — direction: `reverse` *(synthesized)*
