# Build Query Example: Vue 3 (Composition API)

This example demonstrates how to build a highly complex dynamic query payload using Vue 3. 

> [!IMPORTANT]
> Please refer to the [AST Reference Guide](../AST_REFERENCE.md) for the complete, definitive constraints and validation rules of the payload structure.

## The Frontend Implementation

```vue
<template>
  <div class="report-builder">
    <h2>1. Select Base Model</h2>
    <select v-model="payload.baseModel">
      <option value="User">Users</option>
    </select>

    <h2>2. Complex Groupings & Aggregations</h2>
    <button @click="addMultipleGroupBys">Group by User Country AND Product Category</button>
    <button @click="addMultipleAggregates">Calculate Total Revenue AND Order Count</button>

    <h2>3. Complex Filters</h2>
    <button @click="addComplexFilterGroup">Add "Active Users buying Electronics/Software" (Inner Filter)</button>
    <button @click="addComplexHavingFilter">Add "Revenue > 10k AND Orders > 5" (Outer Filter)</button>

    <h2>4. Generate Report</h2>
    <button @click="generateReport" :disabled="loading">
      {{ loading ? 'Generating...' : 'Execute Query' }}
    </button>

    <!-- Results Table -->
    <div v-if="results.length">
      <pre>{{ results }}</pre>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import axios from 'axios';

const loading = ref(false);
const results = ref([]);

const payload = reactive({
  baseModel: 'User',
  targetModels: ['Order', 'Product'],
  selectedAttributes: [],
  innerFilters: null,
  groupBys: [],
  aggregates: [],
  outerFilters: null
});

const addMultipleGroupBys = () => {
    payload.groupBys.push(
        { attribute: { model: 'User', column: 'country', type: 'string' } },
        { attribute: { model: 'Product', column: 'category', type: 'string' } }
    );
};

const addMultipleAggregates = () => {
    payload.aggregates.push(
        {
            attribute: { model: 'Order', column: 'amount', type: 'integer' },
            function: 'SUM',
            alias: 'total_revenue'
        },
        {
            attribute: { model: 'Order', column: 'id', type: 'integer' },
            function: 'COUNT',
            alias: 'total_orders'
        }
    );
};

const addComplexFilterGroup = () => {
    payload.innerFilters = {
        type: 'group',
        logic: 'and',
        children: [
            {
                type: 'leaf',
                attribute: { model: 'User', column: 'status', type: 'string' },
                operator: '=',
                value: 'active'
            },
            {
                type: 'group',
                logic: 'or',
                children: [
                    {
                        type: 'leaf',
                        attribute: { model: 'Product', column: 'category', type: 'string' },
                        operator: '=',
                        value: 'Electronics'
                    },
                    {
                        type: 'leaf',
                        attribute: { model: 'Product', column: 'category', type: 'string' },
                        operator: '=',
                        value: 'Software'
                    }
                ]
            }
        ]
    };
};

const addComplexHavingFilter = () => {
    payload.outerFilters = {
         type: 'group',
         logic: 'and',
         children: [
             {
                 type: 'leaf',
                 attribute: { model: 'Order', column: 'amount', type: 'integer', isVirtual: true },
                 operator: '>',
                 value: 10000
             },
             {
                 type: 'leaf',
                 attribute: { model: 'Order', column: 'id', type: 'integer', isVirtual: true },
                 operator: '>',
                 value: 5
             }
         ]
    };
};

const generateReport = async () => {
  loading.value = true;
  try {
    const response = await axios.post('/api/report/generate', payload);
    results.value = response.data.data;
  } catch (error) {
    console.error("Failed to generate report", error);
  } finally {
    loading.value = false;
  }
};
</script>
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst wants to analyze active user purchasing behavior across specific product categories. They want to see the total revenue and order count, grouped by the user's country and the product's category. They only want to see groupings that generated more than $10,000 in revenue and had more than 5 orders.

**Models Used**: `User`, `Order`, `Product`.

When the user activates the UI buttons above, the Vue reactive state builds and POSTs this exact AST:

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
  },
  "sorts": [
    {
        "attribute": { "model": "Order", "column": "total_revenue", "isVirtual": true },
        "direction": "DESC"
    }
  ]
}
```
