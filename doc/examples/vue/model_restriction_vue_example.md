# Model Restriction Example: Vue 3 (Composition API)

This example demonstrates how an administrator can restrict entire models from being available in the report builder.

## The Frontend Implementation

```vue
<template>
  <div class="model-restriction">
    <h2>Model Access Control</h2>
    <p>Restricted models are completely removed from the report builder.</p>

    <table>
      <thead>
        <tr><th>Model</th><th>Status</th><th>Action</th></tr>
      </thead>
      <tbody>
        <tr v-for="model in allModels" :key="model" :class="{ restricted: isRestricted(model) }">
          <td>{{ model }}</td>
          <td>
            <span v-if="isRestricted(model)" class="badge danger">Restricted</span>
            <span v-else class="badge success">Available</span>
          </td>
          <td>
            <button v-if="isRestricted(model)" @click="unrestrictModel(model)" class="btn-success">
              Unrestrict
            </button>
            <button v-else @click="restrictModel(model)" class="btn-warning">
              Restrict
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';

const allModels = ref([]);
const restrictedModels = ref([]);

const fetchData = async () => {
  const [modelsRes, restrictedRes] = await Promise.all([
    axios.get('/api/schema/models/all'),
    axios.get('/api/admin/models/restricted')
  ]);
  allModels.value = modelsRes.data;
  restrictedModels.value = restrictedRes.data;
};

onMounted(fetchData);

const isRestricted = (model) => restrictedModels.value.some(r => r.model_class === model);

const restrictModel = async (model) => {
  await axios.post('/api/admin/models/restrict', { model_class: model });
  await fetchData();
};

const unrestrictModel = async (model) => {
  await axios.post('/api/admin/models/unrestrict', { model_class: model });
  await fetchData();
};
</script>
```

> [!WARNING]
> Restricting a model flushes the engine's BFS graph cache. Saved reports depending on the restricted model will fail with a `ReportMakerException`.
