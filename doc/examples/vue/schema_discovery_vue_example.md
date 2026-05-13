# Schema Discovery Example: Vue 3 (Composition API)

This example demonstrates how to build a dynamic schema explorer in Vue 3. It queries the Report Generator backend for models, attributes (including Virtual Attributes), and relationships.

## The Frontend Implementation

```vue
<template>
  <div class="schema-explorer">
    <h2>Schema Explorer</h2>

    <!-- Model Selector -->
    <div class="form-group">
      <label>Select Base Model:</label>
      <select v-model="selectedModel" @change="fetchSchemaDetails">
        <option value="">-- Choose a Model --</option>
        <option v-for="model in availableModels" :key="model" :value="model">
          {{ model }}
        </option>
      </select>
    </div>

    <div v-if="loading" class="loading">Loading schema details...</div>

    <div v-else class="schema-details">
      <!-- Attributes List -->
      <div v-if="attributes.length" class="section">
        <h3>Available Attributes</h3>
        <ul>
          <li v-for="attr in attributes" :key="attr">
            {{ attr }}
            <span v-if="attr.startsWith('va:')" class="badge">Virtual</span>
          </li>
        </ul>
      </div>

      <!-- Relationships List -->
      <div v-if="Object.keys(relationships).length" class="section">
        <h3>Discoverable Relationships</h3>
        <ul>
          <li v-for="(rel, targetModel) in relationships" :key="targetModel">
            <strong>{{ targetModel }}</strong> 
            ({{ rel.type }} via <code>{{ rel.methodName }}()</code>)
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';

const availableModels = ref([]);
const selectedModel = ref('');
const attributes = ref([]);
const relationships = ref({});
const loading = ref(false);

// Fetch the allowed models on component mount
onMounted(async () => {
  try {
    const response = await axios.get('/api/schema/models');
    availableModels.value = response.data;
  } catch (error) {
    console.error("Failed to load models", error);
  }
});

// Fetch details when a model is selected
const fetchSchemaDetails = async () => {
  if (!selectedModel.value) {
    attributes.value = [];
    relationships.value = {};
    return;
  }

  loading.value = true;
  try {
    const [attrRes, relRes] = await Promise.all([
      axios.get(`/api/schema/models/${selectedModel.value}/attributes`),
      axios.get(`/api/schema/models/${selectedModel.value}/relationships`)
    ]);

    attributes.value = attrRes.data;
    relationships.value = relRes.data;
  } catch (error) {
    console.error("Failed to load schema details", error);
  } finally {
    loading.value = false;
  }
};
</script>

<style scoped>
.badge {
  background: #e0f7fa;
  color: #006064;
  font-size: 0.8em;
  padding: 2px 6px;
  border-radius: 4px;
  margin-left: 8px;
}
</style>
```

---

## Output Example Context

When the user selects `Order` from the dropdown, the frontend might render the following:

**Available Attributes**
- `id`
- `user_id`
- `amount`
- `created_at`
- `updated_at`
- `va:total_revenue` <span style="color: blue;">(Virtual)</span>

**Discoverable Relationships**
- `User` (BelongsTo via `user()`)
- `Product` (BelongsToMany via `products()`)
