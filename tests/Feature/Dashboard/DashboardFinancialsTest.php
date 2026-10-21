<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Support\Facades\DB;

class DashboardFinancialsTest extends DashboardTestCase
{
    public function test_contract_value_change_orders_and_committed_spend_add_up(): void
    {
        $a = $this->projectA; // budget 100,000

        // Change orders
        $this->co($a, $this->foreman, 'active', ['value' => 5000]);                 // approved → revised contract
        $this->co($a, $this->foreman, 'pending_gc', ['value' => 2000]);             // pending
        $this->co($a, $this->foreman, 'work_authorized', ['work_authorized_at' => now()], 'emergency'); // unpriced
        $this->co($a, $this->foreman, 'work_authorized', ['value' => 700, 'work_authorized_at' => now()], 'emergency'); // pending (priced)
        $this->co($a, $this->foreman, 'gc_rejected', ['value' => 3000, 'work_authorized_at' => now()->subDays(9)], 'emergency'); // disputed
        $this->co($a, $this->foreman, 'cancelled', ['value' => 99999]);             // ignored

        // Purchase orders
        $issued = $this->po($a, 'issued', 10000, ['issued_at' => now()]);
        $this->po($a, 'draft', 4000);
        $this->po($a, 'cancelled', 9999);

        // 40 of 200 units received at 50.00 → 2,000 received
        $unitId = $this->lookupId('units', 'ea');
        $poItem = DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $issued, 'unit_id' => $unitId, 'description' => '2x4 studs',
            'quantity_ordered' => 200, 'unit_price' => 50, 'line_total' => 10000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $delivery = DB::table('deliveries')->insertGetId([
            'purchase_order_id' => $issued, 'received_by' => $this->foreman->id, 'received_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('delivery_items')->insert([
            'delivery_id' => $delivery, 'purchase_order_item_id' => $poItem, 'quantity_received' => 40,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Payment milestones: 8,000 earned of 20,000 scheduled
        $this->milestone($a, today()->subDays(10)->toDateString(), 'completed', [
            'is_payment_milestone' => true, 'payment_amount' => 8000, 'actual_date' => today()->subDays(9)->toDateString(),
        ]);
        $this->milestone($a, today()->addDays(20)->toDateString(), 'not_started', [
            'is_payment_milestone' => true, 'payment_amount' => 12000,
        ]);

        // Noise on another project must not leak into a project-filtered view.
        $this->co($this->projectB, $this->foreman, 'active', ['value' => 77777]);

        $r = $this->dashboard($this->admin, ['project_id' => $a->id])->assertOk();
        $t = $r->json('data.financials.totals');

        $this->assertSame('100000.00', $t['contract_value']);
        $this->assertSame('5000.00', $t['approved_co_value']);
        $this->assertSame('105000.00', $t['revised_contract_value']);
        $this->assertSame('2700.00', $t['pending_co_value']);
        $this->assertSame(1, $t['unpriced_emergency_count']);
        $this->assertSame('3000.00', $t['disputed_co_value']);
        $this->assertSame('10000.00', $t['committed']);
        $this->assertSame('4000.00', $t['draft_po_value']);
        $this->assertSame('2000.00', $t['received']);
        $this->assertEquals(9.5, $t['committed_pct_of_revised']);
        $this->assertSame(['scheduled' => '20000.00', 'earned' => '8000.00', 'remaining' => '12000.00'], $t['payment_milestones']);
        $this->assertSame(1, $t['project_count']);

        $this->assertSame(1, $this->actionCount($r, 'co_needs_pricing'));
        $this->assertSame(1, $this->actionCount($r, 'co_ready_to_forward'));
        $this->assertSame(1, $this->actionCount($r, 'co_disputed'));

        $this->assertNotEmpty($r->json('data.meta.notes'));
    }

    public function test_company_totals_span_all_projects_and_flag_missing_contract_values(): void
    {
        DB::table('projects')->where('id', $this->projectB->id)->update(['budget' => null]);
        $this->co($this->projectB, $this->foreman, 'active', ['value' => 1500]);

        $r = $this->dashboard($this->admin)->assertOk();

        $r->assertJsonPath('data.financials.totals.contract_value', '100000.00')
            ->assertJsonPath('data.financials.totals.revised_contract_value', '101500.00')
            ->assertJsonPath('data.financials.totals.projects_missing_contract_value', 1)
            ->assertJsonPath('data.financials.by_project.0.project.code', 'PRJ-A')
            ->assertJsonPath('data.financials.by_project_truncated', false);
    }

    public function test_financials_are_cached_briefly_and_refresh_bypasses_the_cache(): void
    {
        $first = $this->dashboard($this->admin)->assertOk();
        $this->assertSame('0.00', $first->json('data.financials.totals.committed'));

        $this->po($this->projectA, 'issued', 2500, ['issued_at' => now()]);
        $this->mr($this->projectA, $this->foreman, 'pending_admin');

        $cached = $this->dashboard($this->admin)->assertOk();
        $this->assertSame('0.00', $cached->json('data.financials.totals.committed'));
        $this->assertNotNull($cached->json('data.meta.cached_at.financials'));
        // Queues are never cached.
        $this->assertSame(1, $this->actionCount($cached, 'mr_pending_admin'));

        $fresh = $this->dashboard($this->admin, ['refresh' => 'true'])->assertOk();
        $this->assertSame('2500.00', $fresh->json('data.financials.totals.committed'));
    }
}
