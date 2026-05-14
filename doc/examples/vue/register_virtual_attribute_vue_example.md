# Register Virtual Attribute Example: Vue 3 (Composition API)

This example demonstrates how to build a UI to register a Virtual Attribute (VA) using Vue 3.

## The Frontend Implementation

```vue
<template>
  <div class="va-registrar">
    <h2>Register Virtual Attribute</h2>
    
    <div>
      <label>Name</label>
      <input v-model="form.name" type="text" placeholder="e.g., total_revenue">
    </div>
    
    <div>
      <label>Model</label>
      <select v-model="form.base_model">
        <option value="Order">Order</option>
      </select>
    </div>
    
    <div>
      <label>Return Type</label>
      <select v-model="form.return_type">
        <option value="string">String</option>
        <option value="integer">Integer</option>
        <option value="boolean">Boolean</option>
        <option value="date">Date</option>
      </select>
    </div>

    <div>
      <label>SQL Fragment</label>
      <textarea v-model="form.sql_fragment" placeholder="SUM(orders.amount)"></textarea>
    </div>

    <button @click="registerVA" :disabled="loading">
      {{ loading ? 'Registering...' : 'Register Attribute' }}
    </button>

    <p v-if="message" class="success">{{ message }}</p>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import axios from 'axios';

const loading = ref(false);
const message = ref('');

const form = reactive({
  name: '',
  base_model: 'Order',
  return_type: 'integer',
  sql_fragment: ''
});

const registerVA = async () => {
  loading.value = true;
  message.value = '';
  try {
    await axios.post('/api/va/register', form);
    message.value = "Virtual Attribute registered successfully!";
    form.name = '';
    form.sql_fragment = '';
  } catch (error) {
    console.error("Failed to register VA", error);
  } finally {
    loading.value = false;
  }
};
</script>

<style scoped>
.success { color: green; }
</style>
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
```
