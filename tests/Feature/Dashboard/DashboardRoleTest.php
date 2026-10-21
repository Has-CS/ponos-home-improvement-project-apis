<?php

namespace Tests\Feature\Dashboard;

/**
 * Which sections and queues each role gets, and that project scoping holds.
 */
class DashboardRoleTest extends DashboardTestCase
{
    public function test_admin_sees_company_wide_sections_and_admin_queues_across_all_projects(): void
    {
        $this->mr($this->projectA, $this->foreman, 'pending_admin');
        $this->mr($this->projectB, $this->foreman, 'pending_admin');
        $this->co($this->projectB, $this->foreman, 'pending_admin');
        $this->co($this->projectA, $this->foreman, 'pending_gc', ['sent_to_gc_at' => now()->subDays(6)]);

        $r = $this->dashboard($this->admin)->assertOk();

        $r->assertJsonPath('success', true)
            ->assertJsonPath('data.meta.scope.all_projects', true)
            ->assertJsonPath('data.meta.scope.project_count', 2)
            ->assertJsonPath('data.projects.total', 2);

        foreach (['projects', 'financials', 'milestones', 'procurement', 'change_orders', 'field', 'people', 'pricing', 'charts'] as $section) {
            $this->assertArrayHasKey($section, $r->json('data'), "Admin should see the {$section} section");
        }

        $this->assertSame(2, $this->actionCount($r, 'mr_pending_admin'));
        $this->assertSame(1, $this->actionCount($r, 'co_pending_admin'));
        $this->assertSame(1, $this->actionCount($r, 'co_pending_gc'));
        $this->assertSame(6, $this->actionItem($r, 'co_pending_gc')['preview'][0]['age_days']);

        // Response order: meta, overview sections, action_items just before pricing.
        $this->assertSame(
            ['meta', 'projects', 'financials', 'milestones', 'procurement', 'change_orders', 'field', 'people', 'action_items', 'pricing', 'charts'],
            array_keys($r->json('data')),
        );
        $this->assertSame(array_slice(array_keys($r->json('data')), 1), $r->json('data.meta.sections'));
        $r->assertJsonPath('data.action_items.total', 4)
            ->assertJsonPath('data.action_items.by_priority', ['high' => 3, 'medium' => 1, 'low' => 0])
            ->assertJsonPath('data.people.view', 'company');

        // The PM step belongs to the PM; Admin's own step is pending_admin.
        $this->assertNotContains('mr_pending_pm', $r->json('data.meta.action_item_keys'));

        // Unfiltered Admin view is unbounded, so no per-project health cards.
        $this->assertArrayNotHasKey('health', $r->json('data.projects'));
        $this->assertSame(1, $r->json('data.people.buckets.project_managers'));
        $this->assertSame(1, $r->json('data.people.buckets.foremen'));
    }

    public function test_pm_sees_only_assigned_projects_and_never_their_own_request_to_approve(): void
    {
        $this->mr($this->projectA, $this->foreman, 'pending_pm');
        $this->mr($this->projectA, $this->pm, 'pending_pm');          // their own — not theirs to approve
        $this->mr($this->projectB, $this->foreman, 'pending_pm');     // not their project
        $this->co($this->projectA, $this->foreman, 'pending_pm');
        $this->co($this->projectB, $this->foreman, 'pending_pm');
        $this->co($this->projectA, $this->foreman, 'approved', ['value' => 1000]);
        $this->po($this->projectA, 'draft', 500);
        $this->po($this->projectB, 'draft', 900);

        $r = $this->dashboard($this->pm)->assertOk();

        $r->assertJsonPath('data.meta.scope.all_projects', false)
            ->assertJsonPath('data.meta.scope.project_count', 1)
            ->assertJsonPath('data.projects.total', 1);

        $this->assertSame(1, $this->actionCount($r, 'mr_pending_pm'));
        $this->assertSame(1, $this->actionCount($r, 'co_pending_pm'));
        $this->assertSame(1, $this->actionCount($r, 'co_ready_to_send'));
        $this->assertSame(1, $this->actionCount($r, 'po_draft'));

        // High-priority queues sort ahead of medium ones.
        $this->assertSame('high', $r->json('data.action_items.items.0.priority'));

        $this->assertArrayHasKey('financials', $r->json('data'));
        $this->assertArrayNotHasKey('people', $r->json('data'));
        $this->assertCount(1, $r->json('data.projects.health'));
        $r->assertJsonPath('data.projects.health.0.project.code', 'PRJ-A')
            ->assertJsonPath('data.projects.health.0.pending_material_requests', 2);

        // Project B's numbers never reach the PM.
        $this->assertSame(1, $r->json('data.financials.totals.project_count'));
        $this->assertSame(2, $r->json('data.procurement.material_requests.by_status.pending_pm'));
    }

    public function test_assistant_pm_gets_no_approval_queue_but_does_get_financials(): void
    {
        $this->mr($this->projectA, $this->foreman, 'pending_pm');
        $this->co($this->projectA, $this->foreman, 'pending_pm');

        $r = $this->dashboard($this->apm)->assertOk();

        // PM-level in the services, but the route-level approve_* permission is missing.
        $keys = $r->json('data.meta.action_item_keys');
        $this->assertNotContains('mr_pending_pm', $keys);
        $this->assertNotContains('co_pending_pm', $keys);
        $this->assertContains('mr_returned_to_me', $keys);

        $this->assertArrayHasKey('financials', $r->json('data'));
        $this->assertArrayHasKey('change_orders', $r->json('data'));
    }

    public function test_project_coordinator_has_no_financials_and_no_change_orders(): void
    {
        $this->milestone($this->projectA, today()->subDays(3)->toDateString());

        $r = $this->dashboard($this->coordinator)->assertOk();

        $this->assertArrayNotHasKey('financials', $r->json('data'));
        $this->assertArrayNotHasKey('change_orders', $r->json('data'));
        $this->assertArrayNotHasKey('people', $r->json('data'));
        $this->assertArrayHasKey('milestones', $r->json('data'));
        $this->assertSame(1, $this->actionCount($r, 'milestone_overdue'));
        $this->assertSame(1, $r->json('data.milestones.overdue.count'));
    }

    public function test_foreman_sees_what_was_sent_back_and_todays_log_status(): void
    {
        $this->mr($this->projectA, $this->foreman, 'sent_back_to_foreman');
        $this->mr($this->projectA, $this->siteEngineer, 'sent_back_to_foreman'); // someone else's
        $this->mr($this->projectA, $this->foreman, 'draft');
        $this->co($this->projectA, $this->foreman, 'sent_back');
        $this->issue($this->projectA, $this->pm, 'open', 'medium', $this->foreman);
        $this->milestone($this->projectA, today()->addDays(3)->toDateString(), 'in_progress', ['responsible_user_id' => $this->foreman->id]);

        $r = $this->dashboard($this->foreman)->assertOk();

        $this->assertSame(1, $this->actionCount($r, 'mr_returned_to_me'));
        $this->assertSame(1, $this->actionCount($r, 'mr_my_drafts'));
        $this->assertSame(1, $this->actionCount($r, 'co_returned_to_me'));
        $this->assertSame(1, $this->actionCount($r, 'issue_assigned_to_me'));
        $this->assertSame(1, $this->actionCount($r, 'milestone_mine_due'));
        $this->assertSame(-3, $this->actionItem($r, 'milestone_mine_due')['preview'][0]['age_days']);
        $this->assertSame(1, $this->actionCount($r, 'daily_log_missing_today'));
        $r->assertJsonPath('data.field.my_daily_logs.today.0.filed', false);

        $this->assertArrayNotHasKey('financials', $r->json('data'));
        $this->assertArrayNotHasKey('pricing', $r->json('data'));
        $this->assertSame(1, $r->json('data.procurement.material_requests.mine.by_status.sent_back_to_foreman'));

        // Filing today's log clears the queue and extends the streak.
        $this->dailyLog($this->projectA, $this->foreman, today()->subDay()->toDateString());
        $this->dailyLog($this->projectA, $this->foreman, today()->toDateString());

        $after = $this->dashboard($this->foreman)->assertOk();
        $this->assertSame(0, $this->actionCount($after, 'daily_log_missing_today'));
        $after->assertJsonPath('data.field.my_daily_logs.today.0.filed', true)
            ->assertJsonPath('data.field.my_daily_logs.streak_days', 2);
    }

    public function test_daily_log_queue_is_not_shown_to_a_project_manager(): void
    {
        $r = $this->dashboard($this->pm)->assertOk();

        $this->assertNotContains('daily_log_missing_today', $r->json('data.meta.action_item_keys'));
    }

    public function test_procurement_works_across_projects_without_being_staffed(): void
    {
        $this->mr($this->projectA, $this->foreman, 'approved');
        $this->mr($this->projectB, $this->foreman, 'approved', ['ordering_status' => 'partially_ordered']);
        $this->mr($this->projectB, $this->foreman, 'approved', ['ordering_status' => 'fully_ordered']);
        $this->po($this->projectB, 'sent', 1200, ['expected_delivery_date' => today()->subDays(2)->toDateString()]);

        $r = $this->dashboard($this->procurement)->assertOk();

        $this->assertSame(2, $this->actionCount($r, 'mr_awaiting_po'));
        $this->assertSame(1, $this->actionCount($r, 'po_overdue_delivery'));
        $this->assertSame(2, $r->json('data.financials.totals.project_count'));

        // Membership-scoped sections stay empty: Procurement isn't on any project.
        $r->assertJsonPath('data.projects.total', 0);
        $this->assertArrayNotHasKey('change_orders', $r->json('data'));
    }

    public function test_project_filter_is_scoped_and_validated(): void
    {
        $this->dashboard($this->pm, ['project_id' => $this->projectB->id])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->dashboard($this->pm, ['project_id' => $this->projectA->id])
            ->assertOk()
            ->assertJsonPath('data.meta.filters.project_id', $this->projectA->id);

        $this->dashboard($this->admin, ['project_id' => $this->projectB->id])
            ->assertOk()
            ->assertJsonPath('data.meta.scope.project_count', 1)
            ->assertJsonPath('data.people.project_team_size', 0);

        // Procurement may focus on any project, as with purchase orders.
        $this->dashboard($this->procurement, ['project_id' => $this->projectB->id])->assertOk();

        $this->dashboard($this->admin, ['project_id' => 999999])->assertStatus(422);
    }

    public function test_date_range_is_validated_and_applied(): void
    {
        $this->dashboard($this->admin, ['date_from' => '2026-01-01', 'date_to' => '2027-06-01'])
            ->assertStatus(422)->assertJsonValidationErrors('date_to');
        $this->dashboard($this->admin, ['date_from' => '2026-05-10', 'date_to' => '2026-05-01'])
            ->assertStatus(422)->assertJsonValidationErrors('date_to');
        $this->dashboard($this->admin, ['upcoming_days' => 365])->assertStatus(422);

        $this->dailyLog($this->projectA, $this->foreman, '2026-03-10');
        $this->dailyLog($this->projectA, $this->foreman, '2026-04-20');

        $r = $this->dashboard($this->admin, ['date_from' => '2026-03-01', 'date_to' => '2026-03-31'])->assertOk();

        $r->assertJsonPath('data.meta.filters.date_from', '2026-03-01')
            ->assertJsonPath('data.field.daily_logs.in_range', 1)
            ->assertJsonPath('data.charts.granularity', 'day');

        $series = collect($r->json('data.charts.series'))->firstWhere('key', 'daily_logs');
        $this->assertCount(31, $series['points']);
        $this->assertSame(1, collect($series['points'])->firstWhere('period', '2026-03-10')['value']);
    }
}
