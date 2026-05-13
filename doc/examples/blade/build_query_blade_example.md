# Build Query Example: Laravel Blade (AlpineJS)

This example demonstrates how to build a highly complex dynamic query payload strictly within a Laravel Blade template using AlpineJS for reactivity. 

> [!IMPORTANT]
> Please refer to the [AST Reference Guide](../AST_REFERENCE.md) for the complete, definitive constraints and validation rules of the payload structure.

## The Frontend Implementation

```html
<div x-data="reportBuilder()">
    
    <h2>1. Select Base Model</h2>
    <select x-model="payload.baseModel">
        <option value="User">Users</option>
    </select>

    <h2>2. Complex Groupings & Aggregations</h2>
    <button @click="addMultipleGroupBys">Group by User Country AND Product Category</button>
    <button @click="addMultipleAggregates">Calculate Total Revenue AND Order Count</button>

    <h2>3. Complex Filters</h2>
    <button @click="addComplexFilterGroup">Add "Active Users buying Electronics/Software" (Inner Filter)</button>
    <button @click="addComplexHavingFilter">Add "Revenue > 10k AND Orders > 5" (Outer Filter)</button>

    <h2>4. Generate Report</h2>
    <button @click="generateReport">Execute Query</button>

    <!-- Results Table -->
    <template x-if="results.length > 0">
        <table>
            <!-- Render headers dynamically -->
        </table>
    </template>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('reportBuilder', () => ({
        payload: {
            baseModel: 'User',
            targetModels: ['Order', 'Product'], 
            selectedAttributes: [],
            innerFilters: null,
            groupBys: [], 
            aggregates: [],
            outerFilters: null 
        },
        results: [],

        addMultipleGroupBys() {
            this.payload.groupBys.push(
                { attribute: { modelClass: 'User', column: 'country', type: 'string' } },
                { attribute: { modelClass: 'Product', column: 'category', type: 'string' } }
            );
        },

        addMultipleAggregates() {
            this.payload.aggregates.push(
                {
                    attribute: { modelClass: 'Order', column: 'amount', type: 'integer' },
                    function: 'SUM',
                    alias: 'total_revenue'
                },
                {
                    attribute: { modelClass: 'Order', column: 'id', type: 'integer' },
                    function: 'COUNT',
                    alias: 'total_orders'
                }
            );
        },

        addComplexFilterGroup() {
            this.payload.innerFilters = {
                type: 'group',
                logic: 'and',
                children: [
                    {
                        type: 'leaf',
                        attribute: { modelClass: 'User', column: 'status', type: 'string' },
                        operator: '=',
                        value: 'active'
                    },
                    {
                        type: 'group',
                        logic: 'or',
                        children: [
                            {
                                type: 'leaf',
                                attribute: { modelClass: 'Product', column: 'category', type: 'string' },
                                operator: '=',
                                value: 'Electronics'
                            },
                            {
                                type: 'leaf',
                                attribute: { modelClass: 'Product', column: 'category', type: 'string' },
                                operator: '=',
                                value: 'Software'
                            }
                        ]
                    }
                ]
            };
        },

        addComplexHavingFilter() {
             this.payload.outerFilters = {
                 type: 'group',
                 logic: 'and',
                 children: [
                     {
                         type: 'leaf',
                         attribute: { modelClass: 'Order', column: 'amount', type: 'integer', isVirtual: true },
                         operator: '>',
                         value: 10000
                     },
                     {
                         type: 'leaf',
                         attribute: { modelClass: 'Order', column: 'id', type: 'integer', isVirtual: true },
                         operator: '>',
                         value: 5
                     }
                 ]
             };
        },

        async generateReport() {
            try {
                const response = await fetch('/report/generate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify(this.payload)
                });
                
                const data = await response.json();
                this.results = data.data; 
            } catch (error) {
                console.error("Error building query:", error);
            }
        }
    }));
});
</script>
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst wants to analyze active user purchasing behavior across specific product categories. They want to see the total revenue and order count, grouped by the user's country and the product's category. They only want to see groupings that generated more than $10,000 in revenue and had more than 5 orders.

**Models Used**: `User`, `Order`, `Product`.

If the user clicks the 4 buttons in the UI above, the Blade template will construct and submit this exact AST to the backend:

```json
{
  "baseModel": "User",
  "targetModels": ["Order", "Product"],
  "selectedAttributes": [],
  "groupBys": [
    { "attribute": { "modelClass": "User", "column": "country", "type": "string" } },
    { "attribute": { "modelClass": "Product", "column": "category", "type": "string" } }
  ],
  "aggregates": [
    { 
      "attribute": { "modelClass": "Order", "column": "amount", "type": "integer" },
      "function": "SUM",
      "alias": "total_revenue"
    },
    { 
      "attribute": { "modelClass": "Order", "column": "id", "type": "integer" },
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
        "attribute": { "modelClass": "User", "column": "status", "type": "string" },
        "operator": "=",
        "value": "active"
      },
      {
        "type": "group",
        "logic": "or",
        "children": [
            {
                "type": "leaf",
                "attribute": { "modelClass": "Product", "column": "category", "type": "string" },
                "operator": "=",
                "value": "Electronics"
            },
            {
                "type": "leaf",
                "attribute": { "modelClass": "Product", "column": "category", "type": "string" },
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
            "attribute": { "modelClass": "Order", "column": "amount", "type": "integer", "isVirtual": true },
            "operator": ">",
            "value": 10000
        },
        {
            "type": "leaf",
            "attribute": { "modelClass": "Order", "column": "id", "type": "integer", "isVirtual": true },
            "operator": ">",
            "value": 5
        }
    ]
  },
  "sorts": [
    {
        "attribute": { "modelClass": "Order", "column": "total_revenue", "isVirtual": true },
        "direction": "DESC"
    }
  ]
}
```
