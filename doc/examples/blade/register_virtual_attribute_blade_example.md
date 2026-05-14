# Register Virtual Attribute Example: Laravel Blade (AlpineJS)

This example demonstrates how to build a UI to register a Virtual Attribute (VA) using Laravel Blade and AlpineJS.

## The Frontend Implementation

```html
<div x-data="vaRegistrar()">
    <h2>Register Virtual Attribute</h2>
    
    <label>Name</label>
    <input type="text" x-model="form.name" placeholder="e.g., total_revenue">
    
    <label>Model</label>
    <select x-model="form.base_model">
        <option value="Order">Order</option>
    </select>
    
    <label>Select Type</label>
    <label>Return Type</label>
    <select x-model="form.return_type">
        <option value="string">String</option>
        <option value="integer">Integer</option>
        <option value="boolean">Boolean</option>
        <option value="date">Date</option>
    </select>

    <label>SQL Fragment</label>
    <textarea x-model="form.sql_fragment" placeholder="SUM(orders.amount)"></textarea>

    <button @click="registerVA">Register Attribute</button>

    <div x-show="message" x-text="message" style="color: green;"></div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('vaRegistrar', () => ({
        form: {
            name: '',
            base_modelClass: 'Order',
            return_type: 'integer',
            sql_fragment: ''
        },
        message: '',

        async registerVA() {
            try {
                const response = await fetch('/api/va/register', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify(this.form)
                });
                
                if (response.ok) {
                    this.message = "Virtual Attribute registered successfully!";
                    this.form.name = '';
                    this.form.sql_fragment = '';
                }
            } catch (error) {
                console.error("Error registering VA:", error);
            }
        }
    }));
});
</script>
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst wants to analyze active user purchasing behavior across specific product categories. They want to see the total revenue and order count, grouped by the user's country and the product's category. They only want to see groupings that generated more than $10,000 in revenue and had more than 5 orders. 

Before they can build this AST, an administrator must register `total_revenue` (the sum of order amounts) and `total_orders` (the count of orders) as Virtual Attributes so they can be referenced in the AST's `outerFilters` (HAVING clauses).

**Models Used**: `User`, `Order`, `Product`.

The admin uses the UI above to register the two VAs. Once registered, the user can build the following AST, referencing the VAs using `"isVirtual": true`:

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
