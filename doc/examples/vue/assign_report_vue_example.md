# Vue Example: Report Assignment

This example demonstrates how to build a Vue 3 Composition API component that interacts with the backend Laravel API to assign a `SavedReport` to specific users.

## The Vue Component (`ReportAssignmentModal.vue`)

```vue
<template>
  <div v-if="isOpen" class="modal-overlay">
    <div class="modal-content">
      <h3>Assign Report ID: {{ reportId }}</h3>
      <p>Select the users who are authorized to execute this report:</p>
      
      <div v-if="loading">Loading users...</div>
      
      <div v-else class="user-list">
        <label v-for="user in users" :key="user.id" class="user-checkbox">
          <input 
            type="checkbox" 
            :value="user.id" 
            v-model="selectedUserIds"
          />
          {{ user.name }} ({{ user.email }})
        </label>
      </div>

      <div class="modal-actions" style="margin-top: 20px;">
        <button @click="$emit('close')" :disabled="saving">Cancel</button>
        <button @click="saveAssignments" :disabled="saving" class="btn-primary">
          {{ saving ? 'Saving...' : 'Save Assignments' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import axios from 'axios'

const props = defineProps({
  isOpen: Boolean,
  reportId: Number,
  initialAssignedUsers: {
    type: Array,
    default: () => []
  }
})

const emit = defineEmits(['close'])

const users = ref([])
const selectedUserIds = ref([])
const loading = ref(true)
const saving = ref(false)

// When the modal opens, reset the selected users to the initial prop
watch(() => props.isOpen, (newVal) => {
  if (newVal) {
    selectedUserIds.value = [...props.initialAssignedUsers]
    if (users.value.length === 0) {
      fetchUsers()
    }
  }
})

const fetchUsers = async () => {
  loading.value = true
  try {
    const response = await axios.get('/api/admin/users')
    users.value = response.data
  } catch (error) {
    console.error("Failed to fetch users", error)
  } finally {
    loading.value = false
  }
}

const saveAssignments = async () => {
  saving.value = true
  try {
    // Send the array of User IDs to the sync endpoint
    await axios.post(`/api/admin/reports/${props.reportId}/assign`, {
      user_ids: selectedUserIds.value
    })
    alert('Report assignments updated successfully!')
    emit('close')
  } catch (error) {
    console.error("Failed to assign report", error)
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.user-checkbox {
  display: block;
  margin: 10px 0;
  cursor: pointer;
}
</style>
```
