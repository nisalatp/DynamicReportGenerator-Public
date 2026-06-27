<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Integration;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Nisalatp\DynamicReportGenerator\Builders\ReportBuilder;
use Nisalatp\DynamicReportGenerator\Models\ReportLog;
use Nisalatp\DynamicReportGenerator\Models\SavedReport;
use Nisalatp\DynamicReportGenerator\Types\ReportRequest;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\User;
use Nisalatp\DynamicReportGenerator\Tests\TestCase;

class PersistenceAuditTest extends TestCase
{
    private function sampleRequest(): ReportRequest
    {
        return ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->build();
    }

    private function hasLog(int $reportId, string $action): bool
    {
        return ReportLog::where('saved_report_id', $reportId)->where('action', $action)->exists();
    }

    public function test_save_persists_report_and_logs_created(): void
    {
        $saved = $this->maker()->saveReport('Monthly Users', $this->sampleRequest(), 1, 'desc');

        $this->assertNotNull(SavedReport::find($saved->id));
        $this->assertSame('Monthly Users', $saved->name);
        $this->assertIsArray($saved->payload);             // json cast
        $this->assertSame(User::class, $saved->payload['baseModel']);
        $this->assertTrue($this->hasLog($saved->id, 'created'));
    }

    public function test_load_and_generate_returns_builder_and_logs_executed(): void
    {
        $saved = $this->maker()->saveReport('R', $this->sampleRequest(), 1);

        $builder = $this->maker()->loadAndGenerate($saved->id, 2, []);

        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertCount(2, $builder->get());
        $this->assertTrue($this->hasLog($saved->id, 'executed'));
    }

    public function test_load_to_editor_reconstructs_request(): void
    {
        $saved = $this->maker()->saveReport('R', $this->sampleRequest(), 1);

        $request = $this->maker()->loadToEditor($saved->id);

        $this->assertInstanceOf(ReportRequest::class, $request);
        $this->assertSame(User::class, $request->baseModel);
    }

    public function test_update_logs_updated(): void
    {
        $saved = $this->maker()->saveReport('Before', $this->sampleRequest(), 1);

        $this->maker()->updateReport($saved->id, 'After', $this->sampleRequest(), 'changed', 1);

        $this->assertSame('After', SavedReport::find($saved->id)->name);
        $this->assertTrue($this->hasLog($saved->id, 'updated'));
    }

    public function test_delete_removes_report_and_logs_deleted(): void
    {
        $saved = $this->maker()->saveReport('Doomed', $this->sampleRequest(), 1);
        $id = $saved->id;

        $this->maker()->deleteReport($id, 1);

        $this->assertNull(SavedReport::find($id));

        // The 'deleted' log is written with a null saved_report_id and records
        // the original id in its details payload.
        $deletedLog = ReportLog::where('action', 'deleted')
            ->get()
            ->first(fn ($log) => ($log->details['deleted_report_id'] ?? null) === $id);

        $this->assertNotNull($deletedLog);
        $this->assertNull($deletedLog->saved_report_id);
    }

    public function test_assign_and_unassign_report(): void
    {
        $saved = $this->maker()->saveReport('Shared', $this->sampleRequest(), 1);

        $this->maker()->assignReport($saved->id, 2, 1);
        $this->assertTrue(
            DB::table('dynamic_report_user')
                ->where('saved_report_id', $saved->id)
                ->where('user_id', 2)
                ->exists()
        );
        $this->assertTrue($this->hasLog($saved->id, 'assigned'));

        // Bob (2) sees it via assignment; Alice (1) sees it as owner.
        $this->assertTrue($this->maker()->getAssignedReports(2)->pluck('id')->contains($saved->id));
        $this->assertTrue($this->maker()->getAssignedReports(1)->pluck('id')->contains($saved->id));

        $this->maker()->unassignReport($saved->id, 2, 1);
        $this->assertFalse(
            DB::table('dynamic_report_user')
                ->where('saved_report_id', $saved->id)
                ->where('user_id', 2)
                ->exists()
        );
        $this->assertTrue($this->hasLog($saved->id, 'unassigned'));
    }

    public function test_get_report_logs_orders_most_recent_first(): void
    {
        $saved = $this->maker()->saveReport('R', $this->sampleRequest(), 1); // 'created'
        $this->maker()->loadAndGenerate($saved->id, 1, []);                      // 'executed'

        $logs = $this->maker()->getReportLogs($saved->id);

        $this->assertGreaterThanOrEqual(2, $logs->count());
        $this->assertSame('executed', $logs->first()->action);
    }
}
