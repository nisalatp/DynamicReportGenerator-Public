# Debug SQL & Join Plan Example: Vue 3 (Composition API)

This example demonstrates how to inspect the raw compiled SQL and BFS join plan from a Vue 3 application.

## The Frontend Implementation

```vue
<template>
  <div class="sql-debugger">
    <h2>SQL Debugger & Join Plan Inspector</h2>

    <div class="actions">
      <button @click="fetchRawSql" :disabled="loading">Show Raw SQL</button>
      <button @click="fetchJoinPlan" :disabled="loading">Show BFS Join Plan</button>
    </div>

    <div v-if="rawSql" class="debug-panel">
      <h3>Compiled SQL</h3>
      <pre class="sql-output">{{ rawSql }}</pre>
    </div>

    <div v-if="joinPlan" class="debug-panel">
      <h3>BFS Join Plan</h3>
      <table>
        <thead>
          <tr>
            <th>From</th><th>To</th><th>Type</th>
            <th>Direction</th><th>Local Key</th><th>Foreign Key</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(step, i) in joinPlan.steps" :key="i">
            <td>{{ step.fromModel }}</td>
            <td>{{ step.toModel }}</td>
            <td>{{ step.relationType }}</td>
            <td><span :class="['badge', step.direction]">{{ step.direction }}</span></td>
            <td><code>{{ step.localKey }}</code></td>
            <td><code>{{ step.foreignKey }}</code></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';

const rawSql = ref('');
const joinPlan = ref(null);
const loading = ref(false);

const payload = {
  baseModel: 'User',
  targetModels: ['Order', 'Product'],
  selectedAttributes: [
    { model: 'User', column: 'name', type: 'string' },
    { model: 'Product', column: 'name', type: 'string', alias: 'product_name' }
  ],
  groupBys: [{ attribute: { model: 'User', column: 'country', type: 'string' } }],
  aggregates: [{
    attribute: { model: 'Order', column: 'amount', type: 'integer' },
    function: 'SUM', alias: 'total_revenue'
  }],
  innerFilters: {
    type: 'group', logic: 'and',
    children: [{
      type: 'leaf',
      attribute: { model: 'User', column: 'status', type: 'string' },
      operator: '=', value: 'active'
    }]
  },
  outerFilters: null,
  sorts: [{ attribute: { model: 'Order', column: 'total_revenue', isVirtual: true }, direction: 'DESC' }]
};

const fetchRawSql = async () => {
  loading.value = true;
  try {
    const res = await axios.post('/api/report/debug/sql', payload);
    rawSql.value = res.data.sql;
  } finally { loading.value = false; }
};

const fetchJoinPlan = async () => {
  loading.value = true;
  try {
    const res = await axios.post('/api/report/debug/join-plan', payload);
    joinPlan.value = res.data;
  } finally { loading.value = false; }
};
</script>
```

> [!TIP]
> The `direction` field indicates whether the relationship was explicitly declared (`forward`) or synthesized by the engine's reverse inference (`reverse`).
