# Load Query Example: Vue 3 (Composition API)

This example shows how to fetch a saved report from the database and load its massive AST back into the Vue reactive state.

## The Frontend Implementation

```vue
<template>
  <div class="library">
    <h2>Saved Reports Library</h2>
    <button @click="fetchSavedReports">Browse Library</button>

    <ul v-if="savedReports.length">
      <li v-for="report in savedReports" :key="report.id">
        <strong>{{ report.name }}</strong>
        <p>{{ report.description }}</p>
        <button @click="loadToEditor(report.payload)">Load into Editor</button>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';

const emit = defineEmits(['loadAst']);
const savedReports = ref([]);

const fetchSavedReports = async () => {
  try {
    const response = await axios.get('/api/report/saved');
    savedReports.value = response.data;
  } catch (error) {
    console.error("Error fetching reports", error);
  }
};

const loadToEditor = (payload) => {
  // Ensure payload is an object, not a string
  const parsedAst = typeof payload === 'string' ? JSON.parse(payload) : payload;
  
  // Emit to parent component (the main Builder) to overwrite the reactive AST state
  emit('loadAst', parsedAst);
};
</script>
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst opens the Saved Reports Library and clicks on "High Value Segment Analysis" (analyzing active user purchasing behavior across specific product categories). They want to load this report back into the visual editor so they can modify the HAVING clause filters.

**Models Used**: `User`, `Order`, `Product`.

When the user clicks "Load into Editor", Vue emits `parsedAst` to the parent component. Because Vue is reactive, mutating the root `payload` state with this massive AST structure instantly re-renders the entire visual DOM to reflect these exact parameters:

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
