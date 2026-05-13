# 09. Report Assignment and Access Control

Building sophisticated, dynamic reports is only half the battle in an enterprise environment; controlling who can view and execute those reports is equally critical. The Dynamic Report Generator utilizes a flexible many-to-many relationship structure to manage report distribution.

## The Data Model

When a user saves a report configuration (the serialized AST), it is persisted into the `dynamic_saved_reports` table via the `SavedReport` Eloquent model.

To handle assignments, the package leverages a pivot table called `dynamic_report_user`. This table joins the `SavedReport` model to the standard application `User` model (or any custom Authenticatable model designated by the host application).

```php
// Inside Nisalatp\DynamicReportGenerator\Models\SavedReport

public function assignedUsers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
{
    return $this->belongsToMany(
        \Illuminate\Foundation\Auth\User::class, 
        'dynamic_report_user',
        'saved_report_id',
        'user_id'
    )->withTimestamps();
}
```

## The Assignment Flow

When an Administrator navigates to the "Saved Reports" library and clicks the "Assign" button, the flow operates as follows:

1. **Fetching Targets**: The frontend fetches the list of available Users (and optionally Roles, depending on the host application's extension of the pivot).
2. **Submitting Changes**: The Admin selects the target users (e.g., ticking the box for `analyst@istore.com`) and submits the payload to the `SavedReportController@assign` endpoint.
3. **Synchronization**: The controller retrieves the specific `SavedReport` instance and utilizes Laravel's eloquent `sync()` method on the relationship:
   
   ```php
   $report = SavedReport::findOrFail($id);
   $report->assignedUsers()->sync($request->input('user_ids', []));
   ```

The `sync()` method ensures that the database pivot table perfectly matches the array of IDs provided by the frontend. It automatically inserts new rows for newly assigned users and drops rows for users who were deselected, providing an incredibly clean, stateless approach to access control.

## Execution Constraints

This assignment architecture tightly couples with the execution flow. When a non-administrative user opens their dashboard, the host application queries the `dynamic_saved_reports` table, filtering strictly through the `assignedUsers` pivot relationship. 

If a user's ID does not exist in the pivot table for a specific report, they are entirely unaware the report exists, completely eliminating the risk of unauthorized report execution via brute-force ID guessing.
