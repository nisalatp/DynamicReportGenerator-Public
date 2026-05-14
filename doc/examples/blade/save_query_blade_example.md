# Save Query Example: Laravel Blade (AlpineJS)

This example shows how to save a generated report AST payload into the database for future use, directly from a Blade view using AlpineJS.

## The Frontend Implementation

```html
<div x-data="reportSaver()">
    <!-- Assume `reportPayload` is the AST generated in the Builder tab -->
    
    <h2>Save Report to Library</h2>
    <input type="text" x-model="saveForm.name" placeholder="Report Name">
    <textarea x-model="saveForm.description" placeholder="Optional description..."></textarea>
    
    <button @click="saveReport" :disabled="isSaving">
        <span x-text="isSaving ? 'Saving...' : 'Save Configuration'"></span>
    </button>
    
    <div x-show="message" x-text="message" style="color: green;"></div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('reportSaver', () => ({
        // This AST would normally be pulled from the active builder state
        reportPayload: { baseModel: 'User', targetModels: ['Order', 'Product'], /* ... */ },
        
        saveForm: {
            name: '',
            description: ''
        },
        isSaving: false,
        message: '',

        async saveReport() {
            this.isSaving = true;
            try {
                const response = await fetch('/report/save', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        name: this.saveForm.name,
                        description: this.saveForm.description,
                        // Pass the strict AST directly as the 'payload'
                        payload: this.reportPayload 
                    })
                });
                
                if (response.ok) {
                    this.message = "Report saved successfully!";
                    this.saveForm.name = '';
                }
            } catch (error) {
                console.error("Error saving report:", error);
            } finally {
                this.isSaving = false;
            }
        }
    }));
});
</script>
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst wants to analyze active user purchasing behavior across specific product categories. They want to see the total revenue and order count, grouped by the user's country and the product's category. They only want to see groupings that generated more than $10,000 in revenue and had more than 5 orders. They have just built this query and want to save it as "High Value Segment Analysis".

**Models Used**: `User`, `Order`, `Product`.

When the user clicks "Save Configuration", the `this.reportPayload` object is serialized into the following massive AST, saving the exact state of their complex query into the database for future executions:

```json
{
  "name": "High Value Segment Analysis",
  "description": "Active users buying electronics/software with revenue > $10k",
  "payload": {
    "baseModel": "User",
    "targetModels": ["Order", "Product"],
    "selectedAttributes": [],
    "groupBys": [
      { "attribute": { "model": "User", "column": "country", "type": "string" } },
      { "attribute": { "model": "Product", "column": "category", "type": "string" } }
    ],
    "aggregates": [
      { 
        "attribute": { "model": "Order", "column": "amount", "type": "integer" },
        "function": "SUM",
        "alias": "total_revenue"
      },
      { 
        "attribute": { "model": "Order", "column": "id", "type": "integer" },
        "function": "COUNT",
        "alias": "total_orders"
      }
    ],
    "innerFilters": {
      "type": "group",
      "logic": "and",
      "children": [
        {
          "type": "leaf",
          "attribute": { "model": "User", "column": "status", "type": "string" },
          "operator": "=",
          "value": "active"
        },
        {
          "type": "group",
          "logic": "or",
          "children": [
              {
                  "type": "leaf",
                  "attribute": { "model": "Product", "column": "category", "type": "string" },
                  "operator": "=",
                  "value": "Electronics"
              },
              {
                  "type": "leaf",
                  "attribute": { "model": "Product", "column": "category", "type": "string" },
                  "operator": "=",
                  "value": "Software"
              }
          ]
        }
      ]
    },
    "outerFilters": {
      "type": "group",
      "logic": "and",
      "children": [
          {
              "type": "leaf",
              "attribute": { "model": "Order", "column": "amount", "type": "integer", "isVirtual": true },
              "operator": ">",
              "value": 10000
          },
          {
              "type": "leaf",
              "attribute": { "model": "Order", "column": "id", "type": "integer", "isVirtual": true },
              "operator": ">",
              "value": 5
          }
      ]
    }
  }
}
```
