<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Integration;

use Nisalatp\DynamicReportGenerator\Types\Attribute;
use Nisalatp\DynamicReportGenerator\Types\ReportRequest;
use Nisalatp\DynamicReportGenerator\Types\Sort;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\User;
use Nisalatp\DynamicReportGenerator\Tests\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExportTest extends TestCase
{
    public function test_export_streams_csv_with_headers_and_rows(): void
    {
        // A sort is required: exportToCsv() uses Query Builder chunk(), which
        // throws unless the query has an ORDER BY clause.
        $request = new ReportRequest(
            baseModel: User::class,
            selectedAttributes: [
                new Attribute(User::class, 'name', 'string'),
                new Attribute(User::class, 'email', 'string'),
            ],
            sorts: [new Sort(new Attribute(User::class, 'id', 'integer'), 'ASC')]
        );

        $response = $this->maker()->exportToCsv($request, 'users.csv', []);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('text/csv', $response->headers->get('Content-Type'));

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('name', $csv);   // header row
        $this->assertStringContainsString('Alice', $csv);
        $this->assertStringContainsString('Bob', $csv);
    }
}
