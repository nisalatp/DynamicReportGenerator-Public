# Blade Example: Governance ALS Setup

This example demonstrates how to build a Blade view and Controller logic to fetch and save the Attribute Level Security (ALS) matrix using the `GovernanceManager` service.

The `GovernanceManager` applies restrictions **per subject** — where a subject is any model that implements `DynamicReportSubject` (e.g., `User`, `Role`). The controller accepts a `subject_type` field to dynamically switch between subject classes, letting a single endpoint manage ALS rules for both individual users and roles.

## 1. The Controller (`SecurityConfigController.php`)

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use Nisalatp\DynamicReportGenerator\Services\GovernanceManager;

class SecurityConfigController extends Controller
{
    /**
     * Fetch the security matrix for a given model and subject.
     * subject_type must be 'User' or 'Role'.
     */
    public function getMatrix(Request $request)
    {
        $request->validate([
            'model_class'  => 'required|string',
            'subject_type' => 'required|in:User,Role',
            'subject_id'   => 'required|integer',
        ]);

        // Resolve the subject class from the string the frontend sends.
        // This lets one endpoint manage ALS rules for both users and roles.
        $subjectClass = $request->subject_type === 'User' ? User::class : Role::class;

        $matrix = GovernanceManager::getMatrix(
            $request->model_class,
            $subjectClass,
            $request->subject_id
        );

        return response()->json($matrix);
    }

    /**
     * Save the security matrix for a given model and subject.
     */
    public function save(Request $request)
    {
        $request->validate([
            'model_class'   => 'required|string',
            'subject_type'  => 'required|in:User,Role',
            'subject_id'    => 'required|integer',
            'is_reportable' => 'required|boolean',
            'attributes'    => 'required|array',
        ]);

        $subjectClass = $request->subject_type === 'User' ? User::class : Role::class;

        GovernanceManager::saveMatrix(
            $request->model_class,
            $subjectClass,
            $request->subject_id,
            $request->is_reportable,
            $request->attributes,
            auth()->id()
        );

        return response()->json(['success' => true]);
    }
}
```

## 2. The Blade View (`security.blade.php`)

```html
<div x-data="governanceForm()">
    <h2>Data Governance</h2>

    <!-- Model Selector -->
    <select x-model="selectedModel" @change="fetchMatrix">
        <option value="App\Models\User">User</option>
        <option value="App\Models\Order">Order</option>
        <option value="App\Models\Product">Product</option>
    </select>

    <!-- Subject Type Toggle: apply rules to a Role or a specific User -->
    <select x-model="subjectType" @change="fetchMatrix">
        <option value="Role">By Role</option>
        <option value="User">By User</option>
    </select>

    <!-- Subject ID: populated based on subjectType -->
    <select x-model="subjectId" @change="fetchMatrix">
        <template x-if="subjectType === 'Role'">
            <template x-for="role in roles" :key="role.id">
                <option :value="role.id" x-text="role.name"></option>
            </template>
        </template>
        <template x-if="subjectType === 'User'">
            <template x-for="user in users" :key="user.id">
                <option :value="user.id" x-text="user.name"></option>
            </template>
        </template>
    </select>

    <!-- Matrix Editor -->
    <template x-if="matrix">
        <div>
            <label>
                <input type="checkbox" x-model="matrix.is_reportable">
                Allow Reporting on this Model
            </label>

            <table>
                <thead>
                    <tr><th>Attribute</th><th>Type</th><th>Access Level</th></tr>
                </thead>
                <tbody>
                    <template x-for="attr in matrix.attributes" :key="attr.name">
                        <tr>
                            <td><code x-text="attr.name"></code></td>
                            <td>
                                <span x-show="attr.name.startsWith('va:')"
                                      style="color: blue; font-size: 0.8em;">Virtual</span>
                                <span x-show="!attr.name.startsWith('va:')"
                                      style="color: grey; font-size: 0.8em;">Physical</span>
                            </td>
                            <td>
                                <select x-model="attr.restriction">
                                    <option value="unrestricted">Unrestricted</option>
                                    <option value="masked">Masked (***)</option>
                                    <option value="blocked">Blocked (###)</option>
                                </select>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <button @click="saveMatrix">Save Rules</button>
        </div>
    </template>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('governanceForm', () => ({
        selectedModel: 'App\\Models\\User',
        subjectType: 'Role',   // 'Role' or 'User'
        subjectId: 1,
        matrix: null,
        roles: [],  // pre-populated from Blade: @json($roles)
        users: [],  // pre-populated from Blade: @json($users)

        async fetchMatrix() {
            if (!this.subjectId) return;
            const params = new URLSearchParams({
                model_class:  this.selectedModel,
                subject_type: this.subjectType,
                subject_id:   this.subjectId,
            });
            const res = await fetch(`/admin/security/matrix?${params}`);
            this.matrix = await res.json();
        },

        async saveMatrix() {
            // Flatten the attributes array into a { columnName: restriction } map
            const attributesMap = {};
            this.matrix.attributes.forEach(attr => {
                attributesMap[attr.name] = attr.restriction;
            });

            await fetch('/admin/security/save', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    model_class:   this.selectedModel,
                    subject_type:  this.subjectType,
                    subject_id:    this.subjectId,
                    is_reportable: this.matrix.is_reportable,
                    attributes:    attributesMap
                })
            });
            alert('Saved successfully!');
        }
    }));
});
</script>
```

> [!NOTE]
> **Subject resolution at query time**: The `subject_type` / `subject_id` pair tells `GovernanceManager` *whose* rules to load. When the report is later executed, the engine calls `auth()->user()->getDynamicReportSubjects()` — which returns the user **plus** any roles they belong to — and applies the union of all matching restrictions. A user inherits the strictest rule across all their subjects.
