# Explainer 03: Saving & Loading Logic

This document explores how `builder.blade.php` persists the AST into the database and rehydrates it back into visual UI elements.

## 1. Saving the AST

Saving a report is trivially simple because our state is already a clean JSON object. 

```javascript
async saveReport() {
    await fetch('/report/save', {
        method: 'POST',
        body: JSON.stringify({
            name: this.saveName,
            description: this.saveDescription,
            // We pass the exact payload we use to generate reports!
            payload: this.payload 
        })
    });
}
```
The backend `SavedReport` Eloquent model casts this `payload` column to an `array`.

## 2. Rehydrating the AST (Loading)

Loading a report is technically the most complex part of the frontend. We have a raw JSON object from the database, and we need to overwrite our local `payload` state, which in turn needs to visually update all the checkboxes and dropdowns in the UI.

```javascript
async loadReport(id) {
    const response = await fetch(`/report/saved/${id}`);
    const data = await response.json();
    
    // 1. Safely parse the payload (accounting for string/array formatting)
    let parsedPayload = typeof data.payload === 'string' 
        ? JSON.parse(data.payload) 
        : data.payload;

    // 2. Overwrite the state
    this.payload = parsedPayload;

    // 3. Re-trigger side effects
    await this.fetchAttributes();
    await this.fetchVirtualAttributes();
}
```

### How does the UI update automatically?

Because AlpineJS is reactive, the moment we execute `this.payload = parsedPayload`, Alpine automatically scans the DOM.
For every checkbox, we have a binding like this:

```html
<input type="checkbox" 
       :checked="payload.selectedAttributes.some(a => a.name === attr)">
```

Because `payload.selectedAttributes` was just overwritten with the saved arrays, Alpine evaluates `some(a => a.name === attr)` as `true` for the saved columns, and **automatically checks the boxes in the UI**. 

This is the beauty of the AST architecture—the UI is a pure reflection of the data state.
