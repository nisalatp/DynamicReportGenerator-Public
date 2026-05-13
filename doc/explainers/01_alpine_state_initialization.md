# Explainer 01: AlpineJS State Initialization

This document breaks down the state management logic used in `builder.blade.php`. Since the Dynamic Report Engine requires a strictly formatted Abstract Syntax Tree (AST), the frontend's primary job is to maintain a reactive state that perfectly mirrors that AST.

## The `x-data` Object

In AlpineJS, `x-data` defines the reactive scope. 

```javascript
Alpine.data('reportBuilder', () => ({
    // 1. Core State
    models: [],       // List of available Eloquent models fetched from API
    attributes: {},   // Map of columns for a selected model
    virtualAttributes: [], // List of registered Virtual Attributes
    
    // 2. The AST Payload (Sent to Backend)
    payload: {
        baseModel: '',
        targetModels: [],
        selectedAttributes: [],
        innerFilters: []
    },

    // 3. UI Tracking State (Not sent to backend)
    activeFilters: [],
    loading: false,
    
    // ...
```

### Why this structure?

1. **`payload` is the Source of Truth**: The `payload` object exactly matches the structure of the `Nisalatp\DynamicReportGenerator\Types\ReportRequest` DTO in the backend. 
2. **Reactivity**: When a user clicks a checkbox in the UI, we don't manipulate the DOM. Instead, we push or splice elements from `payload.selectedAttributes`. AlpineJS automatically re-renders the UI to match the new state.
3. **Decoupling**: Notice how there is no SQL or relational logic here. The frontend only cares about *what* the user wants (e.g., "Give me the Total Spend"), not *how* to get it (the backend BFS graph handles the joins).

### Initialization (`init()`)

When the component boots, it fetches the available models:

```javascript
async init() {
    await this.fetchModels();
}
```
This hits the `/report/models` endpoint, populating the `models` array so the user can select their `baseModel`. Once `baseModel` is selected, an `@change` listener triggers `fetchAttributes()` and `fetchVirtualAttributes()`.
