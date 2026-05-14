# Virtual Attribute Management Example: Laravel Blade (AlpineJS)

This example demonstrates how to manage the full lifecycle of Virtual Attributes — listing with usage counts, editing, and safe deletion with dependency checking.

## 1. Backend Routes

```php
use Nisalatp\DynamicReportGenerator\Services\VirtualAttributeManager;
use Nisalatp\DynamicReportGenerator\Models\VirtualAttribute;

Route::get('/admin/virtual-attributes', function () {
    return response()->json(VirtualAttributeManager::getAllWithUsageCounts());
})->middleware('auth');

Route::delete('/admin/virtual-attributes/{id}', function (Request $request, int $id) {
    $force = $request->boolean('force', false);
    try {
        VirtualAttributeManager::safeDelete($id, $force);
        return response()->json(['message' => 'Deleted']);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage(), 'requires_force' => true], 409);
    }
})->middleware('auth');
```

## 2. The Frontend Implementation

```html
<div x-data="vaManager()" x-init="fetchVAs()">
    <h3>Virtual Attribute Manager</h3>

    <table class="table">
        <thead>
            <tr><th>Name</th><th>Base Model</th><th>SQL Fragment</th><th>Usage</th><th>Actions</th></tr>
        </thead>
        <tbody>
            <template x-for="va in virtualAttributes" :key="va.id">
                <tr>
                    <td><code x-text="'va:' + va.name"></code></td>
                    <td x-text="va.base_model.split('\\').pop()"></td>
                    <td><code class="text-muted small" x-text="va.sql_fragment"></code></td>
                    <td>
                        <span :class="va.usage_count > 0 ? 'badge bg-info' : 'badge bg-secondary'"
                              x-text="va.usage_count + ' report(s)'"></span>
                    </td>
                    <td>
                        <button @click="deleteVA(va)" class="btn btn-sm btn-danger"
                                :disabled="deleting === va.id">
                            <span x-text="deleting === va.id ? 'Deleting...' : 'Delete'"></span>
                        </button>
                    </td>
                </tr>
            </template>
        </tbody>
    </table>

    <!-- Force-Delete Confirmation Modal -->
    <div x-show="confirmForce" class="modal-overlay" @click.self="confirmForce = null">
        <div class="modal-content">
            <h4>⚠️ Virtual Attribute In Use</h4>
            <p x-text="confirmForce?.message"></p>
            <p class="text-danger">Force-deleting will break saved reports that depend on this VA.</p>
            <button @click="forceDelete(confirmForce.id)" class="btn btn-danger">Force Delete</button>
            <button @click="confirmForce = null" class="btn btn-secondary">Cancel</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('vaManager', () => ({
        virtualAttributes: [],
        deleting: null,
        confirmForce: null,

        async fetchVAs() {
            const res = await fetch('/admin/virtual-attributes');
            this.virtualAttributes = await res.json();
        },

        async deleteVA(va) {
            this.deleting = va.id;
            const res = await fetch(`/admin/virtual-attributes/${va.id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
            });

            if (res.status === 409) {
                const data = await res.json();
                this.confirmForce = { id: va.id, message: data.error };
            } else {
                await this.fetchVAs();
            }
            this.deleting = null;
        },

        async forceDelete(id) {
            await fetch(`/admin/virtual-attributes/${id}?force=true`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
            });
            this.confirmForce = null;
            await this.fetchVAs();
        }
    }));
});
</script>
```

> [!WARNING]
> `safeDelete()` checks how many saved reports reference the VA in their AST payload. If `usage_count > 0` and `force` is not set, a `409 Conflict` is returned with a descriptive error message.
