# Vue Example: Governance ALS Setup

This example demonstrates how to build a Vue 3 Composition API component that configures the Attribute Level Security (ALS) matrix. The component supports a **subject type toggle** — rules can be applied to a `Role` (affecting all members) or to a specific `User` (individual override).

## The Vue Component (`GovernanceConfig.vue`)

```vue
<template>
  <div class="governance-container">
    <h2>Data Governance (ALS)</h2>

    <div class="selectors">
      <!-- Model selector -->
      <select v-model="selectedModel" @change="fetchMatrix">
        <option value="App\Models\User">User</option>
        <option value="App\Models\Order">Order</option>
        <option value="App\Models\Product">Product</option>
      </select>

      <!-- Subject type toggle -->
      <select v-model="subjectType" @change="onSubjectTypeChange">
        <option value="Role">By Role (affects all role members)</option>
        <option value="User">By User (individual override)</option>
      </select>

      <!-- Subject ID — populated from the chosen type -->
      <select v-model="subjectId" @change="fetchMatrix">
        <option v-for="s in subjectOptions" :key="s.id" :value="s.id">
          {{ s.name }}
        </option>
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
            <th>Type</th>
            <th>Access Level</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(attr, index) in matrix.attributes" :key="attr.name">
            <td><code>{{ attr.name }}</code></td>
            <td :style="{ color: attr.name.startsWith('va:') ? 'blue' : 'grey', fontSize: '0.8em' }">
              {{ attr.name.startsWith('va:') ? 'Virtual' : 'Physical' }}
            </td>
            <td>
              <select v-model="matrix.attributes[index].restriction">
                <option value="unrestricted">Unrestricted</option>
                <option value="masked">Masked (***)</option>
                <option value="blocked">Blocked (###)</option>
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
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'

const props = defineProps({
  roles: { type: Array, default: () => [] },
  users: { type: Array, default: () => [] },
})

const selectedModel = ref('App\\Models\\User')
const subjectType   = ref('Role')   // 'Role' or 'User'
const subjectId     = ref(props.roles[0]?.id ?? 1)
const matrix        = ref(null)
const loading       = ref(false)
const saving        = ref(false)

// Derive the option list from the current subject type
const subjectOptions = computed(() =>
  subjectType.value === 'Role' ? props.roles : props.users
)

// When the subject type changes, reset subjectId to the first item of the new list
const onSubjectTypeChange = () => {
  subjectId.value = subjectOptions.value[0]?.id ?? 1
  fetchMatrix()
}

const fetchMatrix = async () => {
  if (!subjectId.value) return
  loading.value = true
  try {
    const response = await axios.get('/admin/security/matrix', {
      params: {
        model_class:  selectedModel.value,
        subject_type: subjectType.value,
        subject_id:   subjectId.value,
      }
    })
    matrix.value = response.data
  } catch (error) {
    console.error('Failed to fetch matrix', error)
  } finally {
    loading.value = false
  }
}

const saveMatrix = async () => {
  saving.value = true

  // Flatten [{ name, restriction }] → { columnName: restriction }
  const attributesMap = {}
  matrix.value.attributes.forEach(attr => {
    attributesMap[attr.name] = attr.restriction
  })

  try {
    await axios.post('/admin/security/save', {
      model_class:   selectedModel.value,
      subject_type:  subjectType.value,
      subject_id:    subjectId.value,
      is_reportable: matrix.value.is_reportable,
      attributes:    attributesMap,
    })
    alert('Governance rules saved successfully!')
  } catch (error) {
    console.error('Failed to save matrix', error)
  } finally {
    saving.value = false
  }
}

onMounted(() => fetchMatrix())
</script>
```

> [!NOTE]
> **Subject resolution at query time**: Setting rules on a `Role` affects every user that belongs to it. Setting rules on a `User` provides a per-user override. At execution time the engine calls `auth()->user()->getDynamicReportSubjects()` and applies the union of all matching restrictions — the strictest rule wins.
