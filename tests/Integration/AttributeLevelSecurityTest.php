<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Integration;

use Illuminate\Database\Eloquent\Model;
use Nisalatp\DynamicReportGenerator\Builders\ReportBuilder;
use Nisalatp\DynamicReportGenerator\Builders\VirtualAttributeBuilder;
use Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerSecurityException;
use Nisalatp\DynamicReportGenerator\Models\AttributeRestriction;
use Nisalatp\DynamicReportGenerator\Models\RestrictedModel;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\User;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\Order;
use Nisalatp\DynamicReportGenerator\Tests\TestCase;

/**
 * Attribute-Level Security (ALS) Integration Tests
 *
 * Covers the full defense-in-depth surface of the EnforcesSecurity trait:
 *   – Column masking  ('masked'  → '***' in every result row)
 *   – Column blocking ('blocked' → '###' in SELECT; exception when used in computations)
 *   – blocked wins over masked (precedence rule)
 *   – Explicit subjects parameter drives restriction lookup
 *   – unrestrictAttribute() removes a restriction completely
 *   – Model-level restriction hides model from getAvailableModels()
 *   – Virtual-attribute restriction (masked / blocked)
 *   – Filter-depth guard raises an exception when exceeded
 *
 * The fixture dataset inherited from TestCase:
 *   Users:  1 Alice (alice@example.com), 2 Bob (email NULL)
 *   Orders: 1 paid/100, 2 pending/50, 3 paid/200
 */
class AttributeLevelSecurityTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /** Return a minimal Eloquent model that can act as a subject (no DB row needed). */
    private function subject(int $id = 1): Model
    {
        $user = new User();
        $user->id = $id;
        return $user;
    }

    /** Build and execute a simple User(name, email) query with optional subjects. */
    private function runUserEmailQuery(array $subjects = []): \Illuminate\Support\Collection
    {
        $req = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name')
            ->select(User::class, 'email')
            ->sort(User::class, 'name')
            ->build();

        return $this->maker()->generate($req, $subjects)->get();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // A. Masking
    // ──────────────────────────────────────────────────────────────────────────

    public function test_masked_attribute_returns_stars_in_every_row(): void
    {
        $subject = $this->subject(1);
        $this->maker()->restrictAttribute(User::class, 'email', $subject, 'masked');

        $rows = $this->runUserEmailQuery([$subject]);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('***', $row->email, "Expected '***' for masked email, got: {$row->email}");
        }
    }

    public function test_non_restricted_columns_are_still_returned_alongside_masked(): void
    {
        $subject = $this->subject(1);
        $this->maker()->restrictAttribute(User::class, 'email', $subject, 'masked');

        $rows = $this->runUserEmailQuery([$subject]);

        // 'name' must be the real value — only 'email' is masked
        $names = collect($rows)->pluck('name')->sort()->values()->all();
        $this->assertSame(['Alice', 'Bob'], $names);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // B. Blocking
    // ──────────────────────────────────────────────────────────────────────────

    public function test_blocked_attribute_in_select_returns_hash_placeholder(): void
    {
        $subject = $this->subject(1);
        $this->maker()->restrictAttribute(User::class, 'email', $subject, 'blocked');

        $rows = $this->runUserEmailQuery([$subject]);

        foreach ($rows as $row) {
            $this->assertSame('###', $row->email, "Expected '###' for blocked email in SELECT, got: {$row->email}");
        }
    }

    public function test_blocked_attribute_in_filter_throws_security_exception(): void
    {
        $subject = $this->subject(1);
        $this->maker()->restrictAttribute(User::class, 'email', $subject, 'blocked');

        $req = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name')
            ->filter(fn($f) => $f->where(User::class, 'email', 'alice@example.com'))
            ->build();

        $this->expectException(ReportMakerSecurityException::class);
        $this->maker()->generate($req, [$subject])->get();
    }

    public function test_blocked_attribute_in_group_by_throws_security_exception(): void
    {
        $subject = $this->subject(1);
        $this->maker()->restrictAttribute(User::class, 'email', $subject, 'blocked');

        $req = ReportBuilder::forModel(User::class)
            ->select(User::class, 'email')
            ->groupBy(User::class, 'email')
            ->aggregate(User::class, 'id', 'integer', 'COUNT')
            ->build();

        $this->expectException(ReportMakerSecurityException::class);
        $this->maker()->generate($req, [$subject])->get();
    }

    public function test_blocked_attribute_in_sort_throws_security_exception(): void
    {
        $subject = $this->subject(1);
        $this->maker()->restrictAttribute(User::class, 'email', $subject, 'blocked');

        $req = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name')
            ->sort(User::class, 'email')
            ->build();

        $this->expectException(ReportMakerSecurityException::class);
        $this->maker()->generate($req, [$subject])->get();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // C. Precedence: blocked must win over masked
    // ──────────────────────────────────────────────────────────────────────────

    public function test_blocked_takes_precedence_over_masked_for_same_attribute(): void
    {
        $subject = $this->subject(1);

        // Register masked first, then blocked for the same subject+attribute pair
        $this->maker()->restrictAttribute(User::class, 'email', $subject, 'masked');
        $this->maker()->restrictAttribute(User::class, 'email', $subject, 'blocked');

        $rows = $this->runUserEmailQuery([$subject]);

        // Blocked wins → '###', not '***'
        foreach ($rows as $row) {
            $this->assertSame('###', $row->email, "blocked must win over masked; got: {$row->email}");
        }
    }

    public function test_multiple_subjects_most_restrictive_wins(): void
    {
        $subjectA = $this->subject(1); // masked
        $subjectB = $this->subject(2); // blocked

        $this->maker()->restrictAttribute(User::class, 'email', $subjectA, 'masked');
        $this->maker()->restrictAttribute(User::class, 'email', $subjectB, 'blocked');

        // Pass both subjects: blocked (subjectB) must dominate
        $rows = $this->runUserEmailQuery([$subjectA, $subjectB]);

        foreach ($rows as $row) {
            $this->assertSame('###', $row->email, "blocked subject must dominate masked; got: {$row->email}");
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // D. unrestrictAttribute() — removal
    // ──────────────────────────────────────────────────────────────────────────

    public function test_unrestrict_attribute_removes_restriction(): void
    {
        $subject = $this->subject(1);
        $this->maker()->restrictAttribute(User::class, 'email', $subject, 'masked');

        // Confirm it is masked
        $before = $this->runUserEmailQuery([$subject]);
        $this->assertSame('***', $before->first()->email);

        // Remove it
        $this->maker()->unrestrictAttribute(User::class, 'email', $subject);

        // Now the real value should appear
        $after = $this->runUserEmailQuery([$subject]);
        $aliceRow = collect($after)->firstWhere('name', 'Alice');
        $this->assertSame('alice@example.com', $aliceRow->email);
    }

    public function test_unrestrict_does_not_affect_other_subjects(): void
    {
        $subjectA = $this->subject(1);
        $subjectB = $this->subject(2);

        $this->maker()->restrictAttribute(User::class, 'email', $subjectA, 'masked');
        $this->maker()->restrictAttribute(User::class, 'email', $subjectB, 'masked');

        // Remove restriction only for subjectA
        $this->maker()->unrestrictAttribute(User::class, 'email', $subjectA);

        // subjectB still has its restriction
        $rows = $this->runUserEmailQuery([$subjectB]);
        foreach ($rows as $row) {
            $this->assertSame('***', $row->email);
        }

        // subjectA sees the real data
        $rows = $this->runUserEmailQuery([$subjectA]);
        $aliceRow = collect($rows)->firstWhere('name', 'Alice');
        $this->assertSame('alice@example.com', $aliceRow->email);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // E. No subjects → no restrictions applied (open access)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_empty_subjects_array_means_no_restrictions_applied(): void
    {
        // Restrict for subject 1, but run with no subjects at all
        $this->maker()->restrictAttribute(User::class, 'email', $this->subject(1), 'masked');

        // Empty array → resolveAttributeRestrictions returns early
        $rows = $this->runUserEmailQuery([]);

        $aliceRow = collect($rows)->firstWhere('name', 'Alice');
        $this->assertSame('alice@example.com', $aliceRow->email);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // F. getAttributeRestrictions() — API read-back
    // ──────────────────────────────────────────────────────────────────────────

    public function test_get_attribute_restrictions_returns_all_for_subject(): void
    {
        $subject = $this->subject(1);
        $this->maker()->restrictAttribute(User::class, 'email', $subject, 'masked');
        $this->maker()->restrictAttribute(User::class, 'name',  $subject, 'blocked');

        $restrictions = $this->maker()->getAttributeRestrictions($subject);

        $this->assertCount(2, $restrictions);
        $attributes = array_column($restrictions, 'attribute');
        $this->assertContains('email', $attributes);
        $this->assertContains('name', $attributes);
    }

    public function test_get_attribute_restrictions_does_not_bleed_across_subjects(): void
    {
        $subjectA = $this->subject(1);
        $subjectB = $this->subject(2);

        $this->maker()->restrictAttribute(User::class, 'email', $subjectA, 'masked');

        $restrictionsForB = $this->maker()->getAttributeRestrictions($subjectB);
        $this->assertEmpty($restrictionsForB);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // G. Model-level restriction
    // ──────────────────────────────────────────────────────────────────────────

    public function test_restrict_model_removes_it_from_available_models(): void
    {
        $this->assertContains(Order::class, $this->maker()->getAvailableModels());

        $this->maker()->restrictModel(Order::class);

        $this->assertNotContains(Order::class, $this->maker()->getAvailableModels());
    }

    public function test_unrestrict_model_restores_it_to_available_models(): void
    {
        $this->maker()->restrictModel(Order::class);
        $this->assertNotContains(Order::class, $this->maker()->getAvailableModels());

        $this->maker()->unrestrictModel(Order::class);
        $this->assertContains(Order::class, $this->maker()->getAvailableModels());
    }

    public function test_restricting_model_prevents_join_path_resolution(): void
    {
        // Order sits on the join path User→Order→OrderItem→Product.
        // Restricting it should make Order no longer discoverable, breaking the path.
        $this->maker()->restrictModel(Order::class);

        $req = ReportBuilder::forModel(User::class)
            ->withTarget(Order::class)
            ->select(User::class, 'name')
            ->select(Order::class, 'amount', 'float')
            ->build();

        $this->expectException(\Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerException::class);
        $this->maker()->generate($req)->get();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // H. Virtual-attribute restriction
    // ──────────────────────────────────────────────────────────────────────────

    public function test_masked_virtual_attribute_returns_stars(): void
    {
        // Register a simple VA
        VirtualAttributeBuilder::create('test_va')
            ->forBaseModel(User::class)
            ->withReturnType('integer')
            ->withSqlFragment("(SELECT COUNT(*) FROM orders WHERE orders.user_id = {THIS}.id)")
            ->register();

        // Flush the registry cache so the newly registered VA is visible
        $this->app->make(\Nisalatp\DynamicReportGenerator\Registry\VirtualAttributeRegistry::class)->flush();

        $subject = $this->subject(1);
        // VA attribute keys are prefixed 'va:' in the restriction table
        $this->maker()->restrictAttribute(User::class, 'va:test_va', $subject, 'masked');

        $req = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name')
            ->select(User::class, 'test_va', 'integer', true, 'order_count')
            ->sort(User::class, 'name')
            ->build();

        $rows = $this->maker()->generate($req, [$subject])->get();

        foreach ($rows as $row) {
            $this->assertSame('***', $row->order_count, "VA should be masked; got: {$row->order_count}");
        }
    }

    public function test_blocked_virtual_attribute_in_filter_throws(): void
    {
        VirtualAttributeBuilder::create('order_count_va')
            ->forBaseModel(User::class)
            ->withReturnType('integer')
            ->withSqlFragment("(SELECT COUNT(*) FROM orders WHERE orders.user_id = {THIS}.id)")
            ->register();

        $this->app->make(\Nisalatp\DynamicReportGenerator\Registry\VirtualAttributeRegistry::class)->flush();

        $subject = $this->subject(1);
        $this->maker()->restrictAttribute(User::class, 'va:order_count_va', $subject, 'blocked');

        $req = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name')
            ->filter(fn($f) => $f->where(User::class, 'va:order_count_va', '>', 0, 'integer'))
            ->build();

        $this->expectException(ReportMakerSecurityException::class);
        $this->maker()->generate($req, [$subject])->get();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // I. Report Assignment
    // ──────────────────────────────────────────────────────────────────────────

    public function test_assign_makes_report_visible_to_assignee(): void
    {
        $req   = ReportBuilder::forModel(User::class)->select(User::class, 'name')->build();
        $saved = $this->maker()->saveReport('ALS Test Report', $req, 1);

        // Initially user 2 does not own the report
        $beforeAssign = $this->maker()->getAssignedReports(2);
        $this->assertFalse($beforeAssign->contains('id', $saved->id));

        $this->maker()->assignReport($saved->id, 2, 1);

        $afterAssign = $this->maker()->getAssignedReports(2);
        $this->assertTrue($afterAssign->contains('id', $saved->id));
    }

    public function test_unassign_removes_report_from_assignee_but_not_owner(): void
    {
        $req   = ReportBuilder::forModel(User::class)->select(User::class, 'name')->build();
        $saved = $this->maker()->saveReport('Shared Report', $req, 1);

        $this->maker()->assignReport($saved->id, 2, 1);
        $this->maker()->unassignReport($saved->id, 2, 1);

        // Assignee (user 2) no longer sees it
        $this->assertFalse(
            $this->maker()->getAssignedReports(2)->contains('id', $saved->id)
        );

        // Owner (user 1) still sees it via ownership
        $this->assertTrue(
            $this->maker()->getAssignedReports(1)->contains('id', $saved->id)
        );
    }

    public function test_assign_logs_assigned_action(): void
    {
        $req   = ReportBuilder::forModel(User::class)->select(User::class, 'name')->build();
        $saved = $this->maker()->saveReport('Log Test', $req, 1);

        $this->maker()->assignReport($saved->id, 2, 1);

        $logs = $this->maker()->getReportLogs($saved->id);
        $this->assertTrue(
            $logs->contains(fn($log) => $log->action === 'assigned'),
            'Expected an "assigned" audit log entry'
        );
    }

    public function test_unassign_logs_unassigned_action(): void
    {
        $req   = ReportBuilder::forModel(User::class)->select(User::class, 'name')->build();
        $saved = $this->maker()->saveReport('Log Test 2', $req, 1);

        $this->maker()->assignReport($saved->id, 2, 1);
        $this->maker()->unassignReport($saved->id, 2, 1);

        $logs = $this->maker()->getReportLogs($saved->id);
        $this->assertTrue(
            $logs->contains(fn($log) => $log->action === 'unassigned'),
            'Expected an "unassigned" audit log entry'
        );
    }

    public function test_assigned_report_can_be_generated_by_assignee(): void
    {
        $req   = ReportBuilder::forModel(User::class)->select(User::class, 'name')->sort(User::class, 'name')->build();
        $saved = $this->maker()->saveReport('Exec Report', $req, 1);

        $this->maker()->assignReport($saved->id, 2, 1);

        // Assignee (user 2) generates the report — must succeed and return rows
        $rows = $this->maker()->loadAndGenerate($saved->id, 2)->get();
        $this->assertGreaterThan(0, count($rows));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // J. Filter-depth guard
    // ──────────────────────────────────────────────────────────────────────────

    public function test_filter_depth_beyond_limit_throws(): void
    {
        // Default max_filter_depth is 3. We build a 4-level deep tree
        // by adding a leaf at each level so the single-leaf optimization
        // in FilterBuilder::getNode() does NOT collapse the groups away.
        // Structure (each level is a FilterGroup containing a leaf + child group):
        //   depth-1: AND(leaf, depth-2)
        //     depth-2: AND(leaf, depth-3)
        //       depth-3: AND(leaf, depth-4)
        //         depth-4: AND(leaf)   ← this level exceeds the limit of 3
        $req = ReportBuilder::forModel(User::class)
            ->select(User::class, 'name')
            ->filter(function ($f) {
                $f->where(User::class, 'id', '>', 0, 'integer')
                  ->nested(function ($f1) {
                      $f1->where(User::class, 'id', '>', 0, 'integer')
                         ->nested(function ($f2) {
                             $f2->where(User::class, 'id', '>', 0, 'integer')
                                ->nested(function ($f3) {
                                    $f3->where(User::class, 'id', '>', 0, 'integer')
                                       ->nested(function ($f4) {
                                           $f4->where(User::class, 'name', 'Alice');
                                       });
                                });
                         });
                  });
            })
            ->build();

        $this->expectException(\Nisalatp\DynamicReportGenerator\Exceptions\ReportMakerException::class);
        $this->maker()->generate($req)->get();
    }
}
