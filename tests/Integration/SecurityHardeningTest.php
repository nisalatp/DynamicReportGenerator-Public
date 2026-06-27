<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Integration;

use Nisalatp\DynamicReportGenerator\Builders\ReportBuilder;
use Nisalatp\DynamicReportGenerator\Http\Requests\ReportBuilderRequest;
use Nisalatp\DynamicReportGenerator\Types\Aggregate;
use Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerSecurityException;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\User;
use Nisalatp\DynamicReportGenerator\Tests\TestCase;

/**
 * Targeted security hardening tests for v2.1.2 audit fixes.
 *
 * Tests the fixes for:
 *   - VA aggregate function injection (buildScalarSubquery)
 *   - HTTP boundary model class validation
 *   - Auth guard enforcement
 *   - havingRaw parameter binding
 */
class SecurityHardeningTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // A. HTTP Boundary — modelClass validation
    // ──────────────────────────────────────────────────────────────────────────

    public function test_http_payload_with_nonexistent_base_model_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid Eloquent model class');

        ReportBuilderRequest::fromPayload([
            'baseModel' => 'App\\Models\\DoesNotExist',
        ]);
    }

    public function test_http_payload_with_non_model_class_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid Eloquent model class');

        ReportBuilderRequest::fromPayload([
            'baseModel' => \stdClass::class,
        ]);
    }

    public function test_http_payload_silently_strips_invalid_target_models(): void
    {
        $request = ReportBuilderRequest::fromPayload([
            'baseModel' => User::class,
            'targetModels' => [
                'App\\Models\\Ghost',  // doesn't exist
                \stdClass::class,      // not a model
                User::class,           // valid
            ],
            'selectedAttributes' => [
                ['modelClass' => User::class, 'column' => 'name', 'type' => 'string'],
            ],
        ]);

        // Only User should survive the filter
        $this->assertSame([User::class], array_values($request->targetModels));
    }

    public function test_http_payload_with_empty_base_model_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('baseModel');

        ReportBuilderRequest::fromPayload([
            'baseModel' => '',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // B. Auth guard — no-session report execution
    // ──────────────────────────────────────────────────────────────────────────

    public function test_generate_without_auth_or_subjects_throws(): void
    {
        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name')
            ->build();

        $this->expectException(ReportMakerSecurityException::class);
        $this->expectExceptionMessage('without an authenticated user');

        // null subjects + no auth session = must throw
        $this->maker()->generate($request);
    }

    public function test_generate_with_explicit_empty_subjects_succeeds(): void
    {
        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name')
            ->build();

        // [] = explicitly no restrictions — should work fine
        $result = $this->maker()->generate($request, [])->get();
        $this->assertCount(2, $result);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // C. Aggregate function allowlist validation
    // ──────────────────────────────────────────────────────────────────────────

    public function test_aggregate_rejects_sql_injection_in_function_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Aggregate(
            new \Nisalatp\DynamicReportGenerator\Types\Attribute(User::class, 'id', 'integer'),
            "SUM(1)) UNION SELECT password FROM users --"
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // D. Having clause — parameter binding verification
    // ──────────────────────────────────────────────────────────────────────────

    public function test_having_filter_uses_parameter_binding_not_interpolation(): void
    {
        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->select(User::class, 'id', 'integer')
            ->groupBy(User::class, 'name', 'string')
            ->aggregate(User::class, 'id', 'integer', 'COUNT', 'id_count')
            ->having(fn ($f) => $f->where(User::class, 'id_count', '>', 0, 'integer'))
            ->build();

        $query = $this->maker()->generate($request, []);

        // The numeric value should be in bindings, not interpolated in SQL
        $this->assertNotEmpty($query->getBindings());
        $this->assertStringNotContainsString(' 0', $query->toSql()); // '0' should be a '?' binding
    }

    // ──────────────────────────────────────────────────────────────────────────
    // E. toRawSql — proper escaping
    // ──────────────────────────────────────────────────────────────────────────

    public function test_to_raw_sql_escapes_special_characters_safely(): void
    {
        $malicious = "x'; DROP TABLE users; --";

        $request = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name', 'string')
            ->filter(fn ($f) => $f->where(User::class, 'name', '=', $malicious, 'string'))
            ->build();

        $query = $this->maker()->generate($request, []);
        $raw = $this->maker()->toRawSql($query);

        // PDO::quote() escapes the single quote by doubling it (''),
        // so the malicious payload cannot break out of the string literal.
        // The doubled quote is the critical safety property.
        $this->assertStringContainsString("x''", $raw);

        // The table should still exist after query execution
        $query->get();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('users'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // F. FilterBuilder — type preservation in 3-arg shorthand
    // ──────────────────────────────────────────────────────────────────────────

    public function test_filter_builder_preserves_integer_value_type(): void
    {
        $builder = new \Nisalatp\DynamicReportGenerator\Builders\FilterBuilder();
        $node = $builder->where(User::class, 'id', 42)->getNode();

        $this->assertInstanceOf(\Nisalatp\DynamicReportGenerator\Types\FilterLeaf::class, $node);
        $this->assertSame('=', $node->operator);
        $this->assertSame(42, $node->value);       // int, not '42'
    }

    public function test_filter_builder_preserves_float_value_type(): void
    {
        $builder = new \Nisalatp\DynamicReportGenerator\Builders\FilterBuilder();
        $node = $builder->where(User::class, 'balance', 99.99)->getNode();

        $this->assertInstanceOf(\Nisalatp\DynamicReportGenerator\Types\FilterLeaf::class, $node);
        $this->assertSame('=', $node->operator);
        $this->assertSame(99.99, $node->value);     // float, not '99.99'
    }

    public function test_filter_builder_preserves_boolean_value_type(): void
    {
        $builder = new \Nisalatp\DynamicReportGenerator\Builders\FilterBuilder();
        $node = $builder->where(User::class, 'active', true)->getNode();

        $this->assertInstanceOf(\Nisalatp\DynamicReportGenerator\Types\FilterLeaf::class, $node);
        $this->assertSame('=', $node->operator);
        $this->assertSame(true, $node->value);      // bool, not '1'
    }
}
