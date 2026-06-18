# Schema Discovery Example: Laravel Blade (AlpineJS)

> **Backend package — `nisalatp/dynamicreportgenerator` (v1.0.0, MIT):** this frontend example drives the
> published Composer package. Install and configure the Laravel backend first, register your reportable models
> in `config/dynamicreportgenerator.php`, and expose JSON endpoints via the `DynamicReport` facade
> (`generate`, `getAvailableModels`, `getModelAttributes`, `saveReport`, `loadAndGenerate`). Payload schema: [AST_REFERENCE.md](reference/ast-reference).

```bash
composer require nisalatp/dynamicreportgenerator
```


This example demonstrates how to build a dynamic report interface in Laravel Blade and AlpineJS by querying the Report Generator engine for the available schema, eliminating the need to hardcode model structures or manually perform reflection.

## 1. Backend Route Preparation

First, ensure your Laravel routes expose the Engine's discovery methods.

```php
// routes/web.php
use Nisalatp\DynamicReportGenerator\Facades\DynamicReport;

Route::get('/api/schema/models', function () {
    return response()->json(DynamicReport::getAvailableModels());
});

Route::get('/api/schema/models/{model}/attributes', function ($model) {
    return response()->json(DynamicReport::getModelAttributes($model));
});

Route::get('/api/schema/models/{model}/relationships', function ($model) {
    return response()->json(DynamicReport::getModelRelationships($model));
});
```

## 2. The Frontend Implementation

We will use AlpineJS to fetch the available models on initialization, and then fetch attributes and relationships whenever a specific model is selected.

```html
<div x-data="schemaExplorer()" x-init="fetchModels()">
    <h2>Schema Explorer</h2>

    <!-- Model Selector -->
    <div>
        <label>Select Base Model:</label>
        <select x-model="selectedModel" @change="fetchSchemaDetails">
            <option value="">-- Choose a Model --</option>
            <template x-for="model in availableModels" :key="model">
                <option :value="model" x-text="model"></option>
            </template>
        </select>
    </div>

    <!-- Attributes List -->
    <div x-show="attributes.length > 0">
        <h3>Available Attributes</h3>
        <ul>
            <template x-for="attr in attributes" :key="attr">
                <li>
                    <span x-text="attr"></span>
                    <span x-show="attr.startsWith('va:')" style="color: blue; font-size: 0.8em;">(Virtual Attribute)</span>
                </li>
            </template>
        </ul>
    </div>

    <!-- Relationships List -->
    <div x-show="Object.keys(relationships).length > 0">
        <h3>Discoverable Relationships</h3>
        <ul>
            <template x-for="(rel, targetModel) in relationships" :key="targetModel">
                <li>
                    <strong x-text="targetModel"></strong> 
                    (<span x-text="rel.type"></span> via <span x-text="rel.methodName"></span>)
                </li>
            </template>
        </ul>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('schemaExplorer', () => ({
        availableModels: [],
        selectedModel: '',
        attributes: [],
        relationships: {},

        async fetchModels() {
            const response = await fetch('/api/schema/models');
            this.availableModels = await response.json();
        },

        async fetchSchemaDetails() {
            if (!this.selectedModel) {
                this.attributes = [];
                this.relationships = {};
                return;
            }

            // Fetch physical and virtual attributes
            const attrRes = await fetch(`/api/schema/models/${this.selectedModel}/attributes`);
            this.attributes = await attrRes.json();

            // Fetch joinable relationships
            const relRes = await fetch(`/api/schema/models/${this.selectedModel}/relationships`);
            this.relationships = await relRes.json();
        }
    }));
});
</script>
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
- `va:total_revenue` <span style="color: blue;">(Virtual Attribute)</span>

**Discoverable Relationships**
- `User` (BelongsTo via user)
- `Product` (BelongsToMany via products)
