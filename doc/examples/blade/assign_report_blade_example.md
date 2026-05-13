# Blade Example: Report Assignment

This example demonstrates how to build a Blade view and Controller logic to assign a `SavedReport` to specific users, utilizing the many-to-many pivot table in the backend.

## 1. The Controller (`SavedReportController.php`)

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Nisalatp\DynamicReportGenerator\Models\SavedReport;

class SavedReportController extends Controller
{
    /**
     * Display the library of reports and available users.
     */
    public function index()
    {
        // Fetch all reports and eager load assigned users
        $reports = SavedReport::with('assignedUsers')->get();
        
        // Fetch all users to display in the assignment dropdown
        $users = User::all();
        
        return view('admin.reports', compact('reports', 'users'));
    }

    /**
     * Sync the assigned users for a specific report.
     */
    public function assign(Request $request, $id)
    {
        $request->validate([
            'user_ids' => 'array',
            'user_ids.*' => 'integer|exists:users,id'
        ]);

        $report = SavedReport::findOrFail($id);
        
        // The eloquent sync() method automatically drops deselected 
        // users and inserts newly selected users into the dynamic_report_user table
        $report->assignedUsers()->sync($request->input('user_ids', []));

        return redirect()->back()->with('success', 'Report assignments updated successfully!');
    }
}
```

## 2. The Blade View (`reports.blade.php`)

```html
<div class="reports-library">
    <h2>Saved Reports Library</h2>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <table>
        <thead>
            <tr>
                <th>Report Name</th>
                <th>Author</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($reports as $report)
                <tr>
                    <td>{{ $report->name }}</td>
                    <td>{{ $report->user_id }}</td>
                    <td>
                        <!-- Trigger AlpineJS Modal for this Report -->
                        <button @click="$dispatch('open-assign-modal', { id: {{ $report->id }}, assigned: {{ $report->assignedUsers->pluck('id') }} })">
                            Assign Users
                        </button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- AlpineJS Assignment Modal -->
    <div x-data="{ show: false, reportId: null, selectedUsers: [] }" 
         @open-assign-modal.window="show = true; reportId = $event.detail.id; selectedUsers = $event.detail.assigned">
        
        <div x-show="show" class="modal">
            <form :action="'/admin/reports/' + reportId + '/assign'" method="POST">
                @csrf
                <h3>Select Users who can execute this report</h3>
                
                <select name="user_ids[]" x-model="selectedUsers" multiple style="height: 200px;">
                    @foreach($users as $user)
                        <option value="{{ $user->id }}">
                            {{ $user->name }} ({{ $user->email }})
                        </option>
                    @endforeach
                </select>

                <div class="actions">
                    <button type="button" @click="show = false">Cancel</button>
                    <button type="submit" class="btn-primary">Save Assignments</button>
                </div>
            </form>
        </div>
    </div>
</div>
```
