# Blade Example: Governance ALS Setup

This example demonstrates how to build a Blade view and Controller logic to fetch and save the Attribute Level Security (ALS) matrix using the `GovernanceManager` service.

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
     * Fetch the security matrix for a given model and role.
     */
    public function getMatrix(Request $request)
    {
        $request->validate([
            'model_class' => 'required|string',
            'subject_id' => 'required|integer',
        ]);

        $matrix = GovernanceManager::getMatrix(
            $request->model_class,
            Role::class, // We are applying rules to Roles
            $request->subject_id
        );

        return response()->json($matrix);
    }

    /**
     * Save the security matrix.
     */
    public function save(Request $request)
    {
        $payload = $request->validate([
            'model_class' => 'required|string',
            'subject_id' => 'required|integer',
            'is_reportable' => 'required|boolean',
            'attributes' => 'required|array',
        ]);

        GovernanceManager::saveMatrix(
            $payload['model_class'],
            Role::class,
            $payload['subject_id'],
            $payload['is_reportable'],
            $payload['attributes'],
            auth()->id() // Log who made the change
        );

        return response()->json(['success' => true]);
    }
}
```

## 2. The Blade View (`security.blade.php`)

```html
<div x-data="governanceForm()">
    <h2>Data Governance</h2>
    
    <!-- Selectors -->
    <select x-model="selectedModel" @change="fetchMatrix">
        <option value="App\Models\User">User</option>
        <option value="App\Models\Order">Order</option>
    </select>

    <select x-model="selectedRole" @change="fetchMatrix">
        <option value="1">Admin</option>
        <option value="2">Data Analyst</option>
    </select>

    <!-- Matrix Editor -->
    <template x-if="matrix">
        <div>
            <label>
                <input type="checkbox" x-model="matrix.is_reportable"> Allow Reporting on this Model
            </label>

            <table>
                <template x-for="attr in matrix.attributes" :key="attr.name">
                    <tr>
                        <td x-text="attr.name"></td>
                        <td>
                            <select x-model="attr.restriction">
                                <option value="unrestricted">Unrestricted</option>
                                <option value="masked">Masked (***)</option>
                                <option value="blocked">Blocked (Hidden)</option>
                            </select>
                        </td>
                    </tr>
                </template>
            </table>
            
            <button @click="saveMatrix">Save Rules</button>
        </div>
    </template>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('governanceForm', () => ({
        selectedModel: 'App\\Models\\User',
        selectedRole: 2,
        matrix: null,

        async fetchMatrix() {
            const res = await fetch(`/admin/security/matrix?model_class=${this.selectedModel}&subject_id=${this.selectedRole}`);
            this.matrix = await res.json();
        },

        async saveMatrix() {
            // Flatten the attributes array into a key-value object
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
                    model_class: this.selectedModel,
                    subject_id: this.selectedRole,
                    is_reportable: this.matrix.is_reportable,
                    attributes: attributesMap
                })
            });
            alert('Saved successfully!');
        }
    }));
});
</script>
```
