# Load Query Example: Laravel Blade (AlpineJS)

This example shows how to fetch a saved report from the database and "hydrate" or load its massive AST back into the AlpineJS visual builder.

## The Frontend Implementation

```html
<div x-data="reportLoader()">
    <h2>Saved Reports Library</h2>
    <button @click="fetchSavedReports">Browse Library</button>

    <template x-if="savedReports.length > 0">
        <ul>
            <template x-for="report in savedReports" :key="report.id">
                <li>
                    <strong x-text="report.name"></strong>
                    <button @click="loadToEditor(report.payload)">Load into Editor</button>
                </li>
            </template>
        </ul>
    </template>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('reportLoader', () => ({
        savedReports: [],

        async fetchSavedReports() {
            try {
                const response = await fetch('/report/saved');
                this.savedReports = await response.json();
            } catch (error) {
                console.error("Error fetching reports", error);
            }
        },

        loadToEditor(payload) {
            // If the payload is stringified JSON, parse it into an object
            const parsedPayload = typeof payload === 'string' ? JSON.parse(payload) : payload;
            
            // Dispatch event to main builder component to overwrite its active AST
            window.dispatchEvent(new CustomEvent('load-ast-to-builder', { 
                detail: parsedPayload 
            }));
            
            alert("Report loaded into the visual editor!");
        }
    }));
});
</script>
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst opens the Saved Reports Library and clicks on "High Value Segment Analysis" (analyzing active user purchasing behavior across specific product categories). They want to load this report back into the visual editor so they can modify the HAVING clause filters.

**Models Used**: `User`, `Order`, `Product`.

When the user clicks "Load into Editor", the backend returns the database record, and the `report.payload` is parsed. AlpineJS reactively injects this massive AST structure directly into the builder, automatically re-rendering the checkboxes and filters to match:

```json
{
    "baseModel": "User",
    "targetModels": [
        "Order",
        "Product"
    ],
    "selectedAttributes": [],
    "groupBys": [
        {
            "attribute": {
                "modelClass": "User",
                "column": "country",
                "type": "string"
            }
        },
        {
            "attribute": {
                "modelClass": "Product",
                "column": "category",
                "type": "string"
            }
        }
    ],
    "aggregates": [
        {
            "attribute": {
                "modelClass": "Order",
                "column": "amount",
                "type": "integer"
            },
            "function": "SUM",
            "alias": "total_revenue"
        },
        {
            "attribute": {
                "modelClass": "Order",
                "column": "id",
                "type": "integer"
            },
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
                "attribute": {
                    "modelClass": "User",
                    "column": "status",
                    "type": "string"
                },
                "operator": "=",
                "value": "active"
            },
            {
                "type": "group",
                "logic": "or",
                "children": [
                    {
                        "type": "leaf",
                        "attribute": {
                            "modelClass": "Product",
                            "column": "category",
                            "type": "string"
                        },
                        "operator": "=",
                        "value": "Electronics"
                    },
                    {
                        "type": "leaf",
                        "attribute": {
                            "modelClass": "Product",
                            "column": "category",
                            "type": "string"
                        },
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
                "attribute": {
                    "modelClass": "Order",
                    "column": "amount",
                    "type": "integer",
                    "isVirtual": true
                },
                "operator": ">",
                "value": 10000
            },
            {
                "type": "leaf",
                "attribute": {
                    "modelClass": "Order",
                    "column": "id",
                    "type": "integer",
                    "isVirtual": true
                },
                "operator": ">",
                "value": 5
            }
        ]
    }
}
```
