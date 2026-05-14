# Model Restriction Example: React (Hooks)

This example demonstrates how an administrator can restrict entire models from being available in the report builder, and how to manage the restricted models list.

## The Frontend Implementation

```jsx
import React, { useState, useEffect } from 'react';

export default function ModelRestrictionManager() {
  const [allModels, setAllModels] = useState([]);
  const [restrictedModels, setRestrictedModels] = useState([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => { fetchData(); }, []);

  const fetchData = async () => {
    setLoading(true);
    try {
      const [modelsRes, restrictedRes] = await Promise.all([
        fetch('/api/schema/models/all'),      // getAllApplicationModels()
        fetch('/api/admin/models/restricted')  // getRestrictedModels()
      ]);
      setAllModels(await modelsRes.json());
      setRestrictedModels(await restrictedRes.json());
    } catch (error) {
      console.error("Failed to load model data", error);
    } finally {
      setLoading(false);
    }
  };

  const isRestricted = (model) => {
    return restrictedModels.some(r => r.model_class === model);
  };

  // ─── Restrict a Model ───────────────────────────────────────
  const restrictModel = async (model) => {
    try {
      await fetch('/api/admin/models/restrict', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ model_class: model })
      });
      await fetchData();
    } catch (error) {
      console.error("Failed to restrict model", error);
    }
  };

  // ─── Unrestrict a Model ─────────────────────────────────────
  const unrestrictModel = async (model) => {
    try {
      await fetch('/api/admin/models/unrestrict', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ model_class: model })
      });
      await fetchData();
    } catch (error) {
      console.error("Failed to unrestrict model", error);
    }
  };

  return (
    <div className="model-restriction">
      <h2>Model Access Control</h2>
      <p>
        Restricted models are completely removed from the report builder.
        Users will not see them in the model selector dropdown.
      </p>

      {loading && <p>Loading...</p>}

      <table>
        <thead>
          <tr>
            <th>Model</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          {allModels.map(model => (
            <tr key={model} className={isRestricted(model) ? 'restricted' : ''}>
              <td>{model}</td>
              <td>
                {isRestricted(model) 
                  ? <span className="badge danger">Restricted</span>
                  : <span className="badge success">Available</span>
                }
              </td>
              <td>
                {isRestricted(model) ? (
                  <button onClick={() => unrestrictModel(model)} className="btn-success">
                    Unrestrict
                  </button>
                ) : (
                  <button onClick={() => restrictModel(model)} className="btn-warning">
                    Restrict
                  </button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
```

---

## Backend Controller Example

```php
use Nisalatp\DynamicReportGenerator\Facades\DynamicReport;

public function getAllModels()
{
    return response()->json(DynamicReport::getAllApplicationModels());
}

public function getRestrictedModels()
{
    return response()->json(DynamicReport::getRestrictedModels());
}

public function restrict(Request $request)
{
    DynamicReport::restrictModel(
        $request->input('model_class'),
        auth()->id()
    );
    return response()->json(['message' => 'Model restricted']);
}

public function unrestrict(Request $request)
{
    DynamicReport::unrestrictModel($request->input('model_class'));
    return response()->json(['message' => 'Model unrestricted']);
}
```

> [!WARNING]
> When a model is restricted, the engine flushes its `allowedModels` and `cachedLinks` from memory, forcing a recomputation of the BFS graph on the next request. Any saved reports that depend on the restricted model will fail execution with a `ReportMakerException`.
