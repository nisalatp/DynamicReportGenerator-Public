<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Integration;

use Illuminate\Support\Facades\Schema;
use Nisalatp\DynamicReportGenerator\Facades\DynamicReport;
use Nisalatp\DynamicReportGenerator\ReportMaker;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\User;
use Nisalatp\DynamicReportGenerator\Tests\TestCase;

class PackageBootTest extends TestCase
{
    public function test_report_maker_is_a_singleton(): void
    {
        $this->assertSame(
            $this->app->make(ReportMaker::class),
            $this->app->make(ReportMaker::class)
        );
    }

    public function test_package_config_defaults_are_merged(): void
    {
        $this->assertSame(5000, config('dynamicreportgenerator.limits.max_rows'));
        $this->assertFalse(config('dynamicreportgenerator.http.enabled'));
    }

    public function test_package_migrations_create_all_tables(): void
    {
        foreach ([
            'dynamic_saved_reports',
            'dynamic_report_logs',
            'dynamic_report_user',
            'dynamic_virtual_attributes',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        // Columns added by the 000003 "expand" migration.
        $this->assertTrue(Schema::hasColumn('dynamic_report_logs', 'action'));
        $this->assertTrue(Schema::hasColumn('dynamic_report_logs', 'details'));
    }

    public function test_facade_proxies_to_the_engine(): void
    {
        $this->assertContains(User::class, DynamicReport::getAvailableModels());
    }
}
