# Explainer 04: Virtual Attribute Registration

This document traces the logic inside the "Virtual Attribute Playground" tab, where Admin users build the UX Abstraction layer.

## The Goal
A Virtual Attribute takes a complex 3D relational graph (e.g., `Users` -> `Orders` -> `LineItems`) and flattens it into a 2D scalar value (e.g., `Total Lifetime Spend`) using a SQL subquery.

## 1. Visual Mode Construction

If an Admin does not want to write raw SQL, they use the visual dropdowns. The frontend collects these selections and passes them to the backend compilation service.

```javascript
// Payload sent to backend compilation endpoint
const payload = {
    baseModel: 'User',
    ast: [
        { model: 'Order', name: 'total_amount', aggregateFunction: 'SUM' }
    ],
    builderMode: 'visual'
};
```

Instead of the frontend trying to parse relationships and guess table names, the `VirtualAttributeCompiler` service on the PHP backend handles the translation. It uses the Eloquent Reflection API to safely dynamically generate the Subquery Pushdown string (`compileVisualPayload()`).

## 2. Registering the Attribute

Once the SQL fragment is generated (either visually or typed manually in Advanced Mode), it is submitted to the backend.

```javascript
async registerVirtualAttribute() {
    // Note: The backend controller automatically intercepts the payload
    // and delegates to VirtualAttributeCompiler if mode === 'visual'
    const fragment = this.vaForm.mode === 'advanced' 
        ? this.vaForm.advanced.sqlFragment 
        : null;

    await fetch('/va-builder/register', {
        method: 'POST',
        body: JSON.stringify({
            name: this.vaForm.name,
            base_model: this.vaForm.baseModel,
            sql_fragment: fragment,
            
            // We MUST pass dependencies so the Engine knows what tables 
            // to run the BFS Graph algorithm against!
            dependencies: [this.vaForm.visual.targetModel]
        })
    });
}
```

Once registered, the backend `VirtualAttributeRegistry` Singleton caches this SQL fragment. When an End-User subsequently requests this attribute in a report, the Engine automatically injects this SQL fragment into the Laravel Query Builder, achieving O(1) memory efficiency.
