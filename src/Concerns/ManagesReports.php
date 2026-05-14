<?php

namespace Nisalatp\DynamicReportGenerator\Concerns;

use Nisalatp\DynamicReportGenerator\Types\ReportRequest;
use Nisalatp\DynamicReportGenerator\Models\SavedReport;
use Nisalatp\DynamicReportGenerator\Models\ReportLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;

/**
 * Report Management — CRUD operations on SavedReports, assignment, and audit logging.
 *
 * Handles saving, loading, updating, deleting reports, managing user assignments
 * via the pivot table, and writing audit entries to the report log.
 */
trait ManagesReports
{
    /**
     * Write an audit entry to the dynamic_report_logs table.
     */
    private function logAction(?int $savedReportId, ?int $userId, string $action, ?array $details = null): void
    {
        ReportLog::create([
            'saved_report_id' => $savedReportId,
            'user_id' => $userId,
            'action' => $action,
            'details' => $details,
        ]);
    }

    /**
     * Save a report configuration to the database.
     */
    public function saveReport(string $name, ReportRequest $request, ?int $userId = null, string $description = ''): SavedReport
    {
        try {
            $report = SavedReport::create([
                'name' => $name,
                'description' => $description,
                'payload' => json_decode($request->toJson(), true),
                'user_id' => $userId,
            ]);

            $this->logAction($report->id, $userId, 'created');
            return $report;
        } catch (\Throwable $e) {
            $this->logAction(null, $userId, 'error', ['operation' => 'saveReport', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get all saved reports, ordered by most recent.
     */
    public function getSavedReports(): Collection
    {
        return SavedReport::orderBy('created_at', 'desc')->get();
    }

    /**
     * Load a saved report, deserialize it, and execute it through the engine.
     */
    public function loadAndGenerate(int $savedReportId, ?int $executedByUserId = null): Builder
    {
        try {
            $savedReport = SavedReport::findOrFail($savedReportId);

            $json = is_string($savedReport->payload)
                ? $savedReport->payload
                : json_encode($savedReport->payload);

            $request = ReportRequest::fromJson($json);
            $builder = $this->generate($request);

            $this->logAction($savedReport->id, $executedByUserId, 'executed');

            return $builder;
        } catch (\Throwable $e) {
            $this->logAction($savedReportId, $executedByUserId, 'error', ['operation' => 'loadAndGenerate', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get audit logs for a specific saved report.
     */
    public function getReportLogs(int $savedReportId): Collection
    {
        return ReportLog::where('saved_report_id', $savedReportId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Assign a saved report to a user (via pivot table).
     */
    public function assignReport(int $reportId, int $userId, ?int $actionByUserId = null): void
    {
        try {
            $report = SavedReport::findOrFail($reportId);
            $report->assignedUsers()->syncWithoutDetaching([$userId]);
            $this->logAction($reportId, $actionByUserId ?? $report->user_id, 'assigned', ['assigned_user_id' => $userId]);
        } catch (\Throwable $e) {
            $this->logAction($reportId, $actionByUserId, 'error', ['operation' => 'assignReport', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Unassign a saved report from a user.
     */
    public function unassignReport(int $reportId, int $userId, ?int $actionByUserId = null): void
    {
        try {
            $report = SavedReport::findOrFail($reportId);
            $report->assignedUsers()->detach($userId);
            $this->logAction($reportId, $actionByUserId ?? $report->user_id, 'unassigned', ['unassigned_user_id' => $userId]);
        } catch (\Throwable $e) {
            $this->logAction($reportId, $actionByUserId, 'error', ['operation' => 'unassignReport', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get all reports assigned to or owned by a user.
     */
    public function getAssignedReports(int $userId): Collection
    {
        // Fetch reports where the user is either the owner OR explicitly assigned
        return SavedReport::where('user_id', $userId)
            ->orWhereHas('assignedUsers', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Load a saved report's AST for frontend re-hydration.
     */
    public function loadToEditor(int $reportId): ReportRequest
    {
        $savedReport = SavedReport::findOrFail($reportId);
        $json = is_string($savedReport->payload)
            ? $savedReport->payload
            : json_encode($savedReport->payload);

        return ReportRequest::fromJson($json);
    }

    /**
     * Update a saved report's configuration.
     */
    public function updateReport(int $reportId, string $name, ReportRequest $request, string $description = '', ?int $actionByUserId = null): SavedReport
    {
        try {
            $report = SavedReport::findOrFail($reportId);
            $report->update([
                'name' => $name,
                'description' => $description,
                'payload' => json_decode($request->toJson(), true),
            ]);
            $this->logAction($reportId, $actionByUserId ?? $report->user_id, 'updated');
            return $report;
        } catch (\Throwable $e) {
            $this->logAction($reportId, $actionByUserId, 'error', ['operation' => 'updateReport', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Delete a saved report.
     */
    public function deleteReport(int $reportId, ?int $actionByUserId = null): void
    {
        try {
            $report = SavedReport::findOrFail($reportId);
            $report->delete();
            // Since report is deleted, saved_report_id in logs will become null due to "set null" constraint,
            // but we can still insert a log indicating the action.
            $this->logAction(null, $actionByUserId ?? $report->user_id, 'deleted', ['deleted_report_id' => $reportId]);
        } catch (\Throwable $e) {
            $this->logAction($reportId, $actionByUserId, 'error', ['operation' => 'deleteReport', 'message' => $e->getMessage()]);
            throw $e;
        }
    }
}
