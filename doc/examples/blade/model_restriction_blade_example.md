# Model Restriction Example: Laravel Blade (AlpineJS)

This example demonstrates how an administrator can restrict and unrestrict entire models from the report builder interface.

## 1. Backend Routes

```php
use Nisalatp\DynamicReportGenerator\Facades\DynamicReport;

Route::get('/admin/models/all', function () {
    return response()->json(DynamicReport::getAllApplicationModels());
})->middleware('auth');

Route::get('/admin/models/restricted', function () {
    return response()->json(DynamicReport::getRestrictedModels());
})->middleware('auth');

Route::post('/admin/models/restrict', function (Request $request) {
    DynamicReport::restrictModel($request->input('model_class'), auth()->id());
    return response()->json(['message' => 'Model restricted']);
})->middleware('auth');

Route::post('/admin/models/unrestrict', function (Request $request) {
    DynamicReport::unrestrictModel($request->input('model_class'));
    return response()->json(['message' => 'Model unrestricted']);
})->middleware('auth');
```

## 2. The Frontend Implementation

```html
<div x-data="modelRestrictionManager()" x-init="fetchData()">
    <h3>Model Access Control</h3>
    <p class="text-muted">Restricted models are completely removed from the report builder.</p>

    <table class="table">
        <thead>
            <tr><th>Model</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
            <template x-for="model in allModels" :key="model">
                <tr :class="{ 'table-danger': isRestricted(model) }">
                    <td x-text="model"></td>
                    <td>
                        <span x-show="isRestricted(model)" class="badge bg-danger">Restricted</span>
                        <span x-show="!isRestricted(model)" class="badge bg-success">Available</span>
                    </td>
                    <td>
                        <button x-show="isRestricted(model)" @click="unrestrict(model)" class="btn btn-sm btn-success">
                            Unrestrict
                        </button>
                        <button x-show="!isRestricted(model)" @click="restrict(model)" class="btn btn-sm btn-warning">
                            Restrict
                        </button>
                    </td>
                </tr>
            </template>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('modelRestrictionManager', () => ({
        allModels: [],
        restrictedModels: [],

        async fetchData() {
            const [modelsRes, restrictedRes] = await Promise.all([
                fetch('/admin/models/all'),
                fetch('/admin/models/restricted')
            ]);
            this.allModels = await modelsRes.json();
            this.restrictedModels = await restrictedRes.json();
        },

        isRestricted(model) {
            return this.restrictedModels.some(r => r.model_class === model);
        },

        async restrict(model) {
            await fetch('/admin/models/restrict', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ model_class: model })
            });
            await this.fetchData();
        },

        async unrestrict(model) {
            await fetch('/admin/models/unrestrict', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ model_class: model })
            });
            await this.fetchData();
        }
    }));
});
</script>
```

> [!WARNING]
> Restricting a model flushes the BFS graph cache. Saved reports depending on the restricted model will fail with `ReportMakerException`.
