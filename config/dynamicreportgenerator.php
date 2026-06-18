<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reportable Models
    |--------------------------------------------------------------------------
    |
    | When populated, this acts as an explicit whitelist — only the models
    | listed here will be available to the reporting engine. When left empty
    | (the default), the engine auto-discovers all Eloquent models by
    | scanning the application's model directories.
    |
    */
    'reportable_models' => [
        // App\Models\User::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Include Package Models
    |--------------------------------------------------------------------------
    |
    | By default, the package's own infrastructure models (SavedReport,
    | ReportLog, RestrictedModel, AttributeRestriction, VirtualAttribute)
    | are automatically excluded from reportable tables. Set this to true
    | if you need to expose them for reporting or auditing purposes.
    |
    */
    'include_package_models' => false,

    /*
    |--------------------------------------------------------------------------
    | Execution Limits
    |--------------------------------------------------------------------------
    |
    | Limits applied to the execution of generated reports.
    |
    */
    'limits' => [
        'max_rows' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Configuration (Optional)
    |--------------------------------------------------------------------------
    |
    | If enabled, the package will register its optional API endpoints.
    |
    */
    'http' => [
        'enabled' => false,
        'prefix' => 'dynamic-reporting',
        'middleware' => ['web', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Configuration
    |--------------------------------------------------------------------------
    |
    | Variables specifically for frontend implementations of the report builder.
    |
    */
    'ui' => [
        'max_filter_depth' => 3,
    ],
];
