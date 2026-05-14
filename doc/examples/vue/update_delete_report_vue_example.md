# Update & Delete Report Example: Vue 3 (Composition API)

This example demonstrates how to update and delete saved report configurations from a Vue 3 application.

## The Frontend Implementation

```vue
<template>
  <div class="report-manager">
    <h2>Report Library</h2>

    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Description</th>
          <th>Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="report in savedReports" :key="report.id">
          <td>{{ report.name }}</td>
          <td>{{ report.description || '—' }}</td>
          <td>{{ new Date(report.created_at).toLocaleDateString() }}</td>
          <td>
            <button @click="startEditing(report)">Edit</button>
            <button @click="deleteReport(report.id)" class="btn-danger">Delete</button>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- Edit Modal -->
    <div v-if="editingReport" class="edit-modal">
      <h3>Edit Report: {{ editingReport.name }}</h3>
      <input v-model="editName" type="text" placeholder="Report Name" />
      <textarea v-model="editDescription" placeholder="Description"></textarea>
      <button @click="updateReport" :disabled="loading">
        {{ loading ? 'Saving...' : 'Save Changes' }}
      </button>
      <button @click="editingReport = null">Cancel</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';

const savedReports = ref([]);
const editingReport = ref(null);
const editName = ref('');
const editDescription = ref('');
const loading = ref(false);

const fetchReports = async () => {
  const res = await axios.get('/api/report/saved');
  savedReports.value = res.data;
};

onMounted(fetchReports);

const startEditing = (report) => {
  editingReport.value = report;
  editName.value = report.name;
  editDescription.value = report.description || '';
};

const updateReport = async () => {
  loading.value = true;
  try {
    await axios.put(`/api/report/saved/${editingReport.value.id}`, {
      name: editName.value,
      description: editDescription.value
    });
    editingReport.value = null;
    await fetchReports();
  } catch (error) {
    console.error("Failed to update report", error);
  } finally {
    loading.value = false;
  }
};

const deleteReport = async (id) => {
  if (!confirm('Are you sure you want to delete this report?')) return;
  loading.value = true;
  try {
    await axios.delete(`/api/report/saved/${id}`);
    await fetchReports();
  } catch (error) {
    console.error("Failed to delete report", error);
  } finally {
    loading.value = false;
  }
};
</script>
```

> [!NOTE]
> Both `updateReport()` and `deleteReport()` automatically log the action to `dynamic_report_logs` for audit compliance.
