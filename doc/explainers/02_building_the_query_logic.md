# Explainer 02: Building the Query Logic

This document traces the logic of how user clicks are translated into the AST array within `builder.blade.php`.

## 1. Selecting Attributes

When a user clicks a checkbox next to a column (e.g., `email`) or a Virtual Attribute (e.g., `Total Spend`), it triggers `toggleAttribute()`:

```javascript
toggleAttribute(model, attrName) {
    const exists = this.payload.selectedAttributes.findIndex(
        a => a.name === attrName && a.model === model
    );
    
    if (exists !== -1) {
        // If it was already checked, remove it from the AST
        this.payload.selectedAttributes.splice(exists, 1);
    } else {
        // If it was unchecked, add it to the AST
        this.payload.selectedAttributes.push({ 
            model: model, 
            name: attrName, 
            aggregateFunction: null 
        });
    }
}
```

This single function is the heart of the "Data Democratization" concept. The user thinks they are just checking boxes, but Alpine is silently building a strict schema of `Attribute` DTOs.

## 2. Building Filters

Filters are more complex because they involve nested groups and operators. When a user clicks "Add Filter", we push a `FilterLeaf` object into `payload.innerFilters`.

```javascript
addFilter() {
    this.payload.innerFilters.push({
        type: 'leaf',
        attribute: { model: this.payload.baseModel, name: '', aggregateFunction: null },
        operator: '=',
        value: ''
    });
}
```

Notice how the `FilterLeaf` explicitly defines its type as `'leaf'`. This is crucial because the PHP backend recursively parses the JSON, checking if `type === 'leaf'` or `type === 'group'` to build the proper nested `WHERE` clauses.

## 3. Submitting the Payload

When the user clicks "Generate", the frontend simply stringifies the `payload` object and posts it:

```javascript
const response = await fetch('/report/generate', {
    method: 'POST',
    body: JSON.stringify(this.payload) // Safe, strict JSON payload
});
```
Because the UI is completely decoupled from the execution, we can safely pass this JSON to the backend, completely eliminating SQL injection risks since we are never sending raw query strings.
