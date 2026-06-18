# Save Query Example: Vue 3 (Composition API)

> **Backend package — `nisalatp/dynamicreportgenerator` (v1.0.0, MIT):** this frontend example drives the
> published Composer package. Install and configure the Laravel backend first, register your reportable models
> in `config/dynamicreportgenerator.php`, and expose JSON endpoints via the `DynamicReport` facade
> (`generate`, `getAvailableModels`, `getModelAttributes`, `saveReport`, `loadAndGenerate`). Payload schema: [AST_REFERENCE.md](reference/ast-reference).

```bash
composer require nisalatp/dynamicreportgenerator
```


This example shows how to save a generated report AST payload into the database using Vue 3.

## The Frontend Implementation

```vue
<template>
  <div class="save-report-panel">
    <h2>Save Report to Library</h2>
    
    <div class="form-group">
      <label>Report Name</label>
      <input v-model="form.name" type="text" placeholder="e.g., Monthly Sales">
    </div>
    
    <div class="form-group">
      <label>Description</label>
      <textarea v-model="form.description"></textarea>
    </div>
    
    <button @click="saveReport" :disabled="loading || !form.name">
      {{ loading ? 'Saving...' : 'Save Configuration' }}
    </button>
    
    <p v-if="successMsg" class="success">{{ successMsg }}</p>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import axios from 'axios';

// Assuming `currentAst` is passed as a prop from the Builder component
const props = defineProps({
  currentAst: Object
});

const loading = ref(false);
const successMsg = ref('');

const form = reactive({
  name: '',
  description: ''
});

const saveReport = async () => {
  loading.value = true;
  successMsg.value = '';
  
  try {
    await axios.post('/api/report/save', {
      name: form.name,
      description: form.description,
      payload: props.currentAst // The raw AST object is persisted natively
    });
    
    successMsg.value = "Report successfully saved to the library!";
    form.name = '';
    form.description = '';
  } catch (error) {
    console.error("Failed to save report", error);
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

**Scenario Description**: A business analyst wants to analyze active user purchasing behavior across specific product categories. They want to see the total revenue and order count, grouped by the user's country and the product's category. They only want to see groupings that generated more than $10,000 in revenue and had more than 5 orders. They have just built this query and want to save it as "High Value Segment Analysis".

**Models Used**: `User`, `Order`, `Product`.

When the user clicks "Save Configuration" in the Vue app, `props.currentAst` is passed directly to the backend. The payload submitted over the network looks exactly like this:

```json
{
  "name": "High Value Segment Analysis",
  "description": "Active users buying electronics/software with revenue > $10k",
  "payload": {
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
    }
  }
}
```
