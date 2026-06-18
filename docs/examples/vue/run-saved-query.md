# Run Saved Query Example: Vue 3 (Composition API)

> **Backend package — `nisalatp/dynamicreportgenerator` (v1.0.0, MIT):** this frontend example drives the
> published Composer package. Install and configure the Laravel backend first, register your reportable models
> in `config/dynamicreportgenerator.php`, and expose JSON endpoints via the `DynamicReport` facade
> (`generate`, `getAvailableModels`, `getModelAttributes`, `saveReport`, `loadAndGenerate`). Payload schema: [AST_REFERENCE.md](reference/ast-reference).

```bash
composer require nisalatp/dynamicreportgenerator
```


This example shows how to directly execute a saved report configuration from the database without needing to load its AST into the builder first.

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
        <button @click="executeSavedReport(report.id)" :disabled="loading">
          {{ loading ? 'Running...' : 'Run Report Directly' }}
        </button>
      </li>
    </ul>

    <div v-if="results.length">
      <h3>Results:</h3>
      <pre>{{ results }}</pre>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';

const savedReports = ref([]);
const results = ref([]);
const loading = ref(false);

const fetchSavedReports = async () => {
  try {
    const response = await axios.get('/api/report/saved');
    savedReports.value = response.data;
  } catch (error) {
    console.error("Error fetching reports", error);
  }
};

const executeSavedReport = async (id) => {
  loading.value = true;
  try {
    // Notice we hit the /execute endpoint and only pass the ID.
    // The backend handles loading the AST and compiling the SQL.
    const response = await axios.post(`/api/report/saved/${id}/execute`);
    results.value = response.data.data;
  } catch (error) {
    console.error("Failed to execute saved report", error);
  } finally {
    loading.value = false;
  }
};
</script>
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst opens the Saved Reports Library and clicks on "High Value Segment Analysis" (analyzing active user purchasing behavior across specific product categories). They do NOT want to modify it. They just want to see the latest data. They click "Run Report Directly".

**Models Used**: `User`, `Order`, `Product`.

The frontend only passes the `report.id`. The backend fetches the record from the database and implicitly hydrates this massive AST payload. It then validates it, compiles it into SQL, executes it, and returns the data array directly to Vue:

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
  }
}
```
