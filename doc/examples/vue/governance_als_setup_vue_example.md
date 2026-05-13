# Vue Example: Governance ALS Setup

This example demonstrates how to build a Vue 3 Composition API component that interacts with the backend Laravel API to configure the Attribute Level Security (ALS) matrix.

## The Vue Component (`GovernanceConfig.vue`)

```vue
<template>
  <div class="governance-container">
    <h2>Data Governance (ALS)</h2>
    
    <div class="selectors">
      <select v-model="selectedModel" @change="fetchMatrix">
        <option value="App\Models\User">User</option>
        <option value="App\Models\Order">Order</option>
      </select>

      <select v-model="selectedRole" @change="fetchMatrix">
        <option :value="1">Admin</option>
        <option :value="2">Data Analyst</option>
      </select>
    </div>

    <p v-if="loading">Loading rules...</p>

    <div v-if="matrix && !loading" class="matrix-editor">
      <label>
        <input type="checkbox" v-model="matrix.is_reportable" />
        Allow Reporting on this Model
      </label>

      <table>
        <thead>
          <tr>
            <th>Attribute</th>
            <th>Access Level</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(attr, index) in matrix.attributes" :key="attr.name">
            <td>{{ attr.name }}</td>
            <td>
              <select v-model="matrix.attributes[index].restriction">
                <option value="unrestricted">Unrestricted</option>
                <option value="masked">Masked (***)</option>
                <option value="blocked">Blocked (Hidden)</option>
              </select>
            </td>
          </tr>
        </tbody>
      </table>
      
      <button @click="saveMatrix" class="btn-primary" :disabled="saving">
        {{ saving ? 'Saving...' : 'Save Security Matrix' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const selectedModel = ref('App\\Models\\User')
const selectedRole = ref(2)
const matrix = ref(null)
const loading = ref(false)
const saving = ref(false)

const fetchMatrix = async () => {
  loading.value = true
  try {
    const response = await axios.get('/api/admin/security/matrix', {
      params: {
        model_class: selectedModel.value,
        subject_id: selectedRole.value
      }
    })
    matrix.value = response.data
  } catch (error) {
    console.error("Failed to fetch matrix", error)
  } finally {
    loading.value = false
  }
}

const saveMatrix = async () => {
  saving.value = true
  
  // Flatten the attributes array into a key-value mapping object
  const attributesMap = {}
  matrix.value.attributes.forEach(attr => {
    attributesMap[attr.name] = attr.restriction
  })

  try {
    await axios.post('/api/admin/security/save', {
      model_class: selectedModel.value,
      subject_id: selectedRole.value,
      is_reportable: matrix.value.is_reportable,
      attributes: attributesMap
    })
    alert('Governance rules saved successfully!')
  } catch (error) {
    console.error("Failed to save matrix", error)
  } finally {
    saving.value = false
  }
}

// Fetch initial data on mount
onMounted(() => {
  fetchMatrix()
})
</script>
```
