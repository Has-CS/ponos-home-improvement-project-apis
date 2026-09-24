<?php

namespace Tests\Feature\PurchaseOrder;

use App\Models\CatalogItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestStatus;
use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
use App\Models\PurchaseOrder;
use App\Models\Urgency;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Document\DocumentSequenceService;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PO numbers read `SHORTCODE-YYYY-MM-00001` — project initials, year, 2-digit
 * month, 5-digit series.
 *
 * The series counts PER PROJECT and never resets: the month in the number moves
 * on, the counter keeps going. The counter itself comes from
 * DocumentSequenceService's atomic UPDATE, which is what makes two simultaneous
 * creates unable to collide.
 */
class PurchaseOrderNumberTest extends TestCase
{
    use RefreshDatabase;

    private User $procurement;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->procurement = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole(
            $this->procurement,
            Role::where('name', 'Procurement')->where('guard_name', 'api')->whereNull('project_id')->firstOrFail(),
        );

        $this->vendor = Vendor::create([
            'name' => 'PO Number Test Supply Co.',
            'email' => 'orders@ponumbertest.com',
        ]);

        Carbon::setTestNow('2026-09-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function project(string $shortCode, string $name = 'Test Project'): Project
    {
        $project = Project::factory()->create(['name' => $name, 'short_code' => $shortCode]);
        ProjectDeliveryAddress::factory()->primary()->create(['project_id' => $project->id]);

        return $project;
    }

    /** Raise a PO against a fresh approved request and return its number. */
    private function poNumber(Project $project): string
    {
        $mr = MaterialRequest::create([
            'request_no' => 'MR-'.fake()->unique()->numerify('######'),
            'project_id' => $project->id,
            'requested_by' => $this->procurement->id,
            'material_request_status_id' => MaterialRequestStatus::where('code', 'approved')->value('id'),
            'urgency_id' => Urgency::where('code', 'normal')->value('id'),
            'created_by' => $this->procurement->id,
        ]);

        return $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mr->id,
            'vendor_id' => $this->vendor->id,
            'items' => [[
                'catalog_item_id' => CatalogItem::factory()->create()->id,
                'quantity_ordered' => 1,
                'unit_price' => 10,
            ]],
        ])->assertStatus(201)->json('data.po_number');
    }

    /* ---------------- format ---------------- */

    public function test_the_first_order_of_a_project_is_numbered_from_one(): void
    {
        $this->assertSame('SJ-2026-09-00001', $this->poNumber($this->project('SJ', 'Surbana Jhons')));
    }

    public function test_the_series_increments(): void
    {
        $project = $this->project('SJ', 'Surbana Jhons');

        $this->assertSame('SJ-2026-09-00001', $this->poNumber($project));
        $this->assertSame('SJ-2026-09-00002', $this->poNumber($project));
        $this->assertSame('SJ-2026-09-00003', $this->poNumber($project));
    }

    /* ---------------- the series does not reset ---------------- */

    public function test_a_new_month_moves_the_date_but_not_the_series(): void
    {
        $project = $this->project('SJ', 'Surbana Jhons');

        $this->poNumber($project);
        $this->poNumber($project);
        $this->poNumber($project);

        Carbon::setTestNow('2026-10-02 09:00:00');

        // The month advances; the counter carries straight on from 3.
        $this->assertSame('SJ-2026-10-00004', $this->poNumber($project));
    }

    public function test_a_new_year_moves_the_date_but_not_the_series(): void
    {
        $project = $this->project('SJ', 'Surbana Jhons');

        $this->poNumber($project);

        Carbon::setTestNow('2027-01-04 09:00:00');

        $this->assertSame('SJ-2027-01-00002', $this->poNumber($project));
    }

    /* ---------------- each project counts on its own ---------------- */

    public function test_projects_keep_independent_series(): void
    {
        $first = $this->project('SJ', 'Surbana Jhons');
        $second = $this->project('SV', 'Silcone Village');

        $this->poNumber($first);
        $this->poNumber($first);
        $this->assertSame('SJ-2026-09-00003', $this->poNumber($first));

        // The second project starts at 1, not 4.
        $this->assertSame('SV-2026-09-00001', $this->poNumber($second));
        $this->assertSame('SV-2026-09-00002', $this->poNumber($second));

        // And the first is unaffected by the second.
        $this->assertSame('SJ-2026-09-00004', $this->poNumber($first));
    }

    /* ---------------- concurrency ---------------- */

    /**
     * Every value handed out is distinct, and the counter never skips or repeats.
     *
     * Honest about what this proves. A genuine two-process race cannot be staged
     * from inside RefreshDatabase — the test itself runs in a transaction, so a
     * second connection sees none of its data and any "other session" assertion
     * would pass trivially. What is verified here is that the counter is the sole
     * source of the number and advances one at a time; the collision guarantee
     * comes from the shape of the statement — a single atomic
     * `UPDATE … SET last_value = last_value + 1 … RETURNING`, which takes a row
     * lock in Postgres, so a concurrent caller waits and then reads the value the
     * first one wrote. That is asserted structurally below, and the partial
     * unique index on po_number is the backstop if it were ever bypassed.
     */
    public function test_every_minted_value_is_distinct_and_gapless(): void
    {
        $sequences = app(DocumentSequenceService::class);

        $values = [];
        for ($i = 0; $i < 25; $i++) {
            $values[] = $sequences->nextValue('purchase_order', 'project', 4242, 'SJ');
        }

        $this->assertSame(range(1, 25), $values);
        $this->assertCount(25, array_unique($values));
    }

    /** The increment must be one atomic statement, not read-then-write. */
    public function test_the_counter_is_incremented_by_a_single_atomic_statement(): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        app(DocumentSequenceService::class)->nextValue('purchase_order', 'project', 4242, 'SJ');

        $increments = array_values(array_filter(
            $statements,
            fn (string $sql) => str_contains($sql, 'UPDATE document_sequences') || str_contains($sql, 'update "document_sequences"'),
        ));

        $this->assertCount(1, $increments, 'The value must be reserved by exactly one statement.');
        $this->assertStringContainsString('last_value = last_value + 1', $increments[0]);
        $this->assertStringContainsString('RETURNING last_value', $increments[0]);

        // Nothing reads the counter first — a SELECT-then-UPDATE is the racy shape.
        $this->assertSame(
            [],
            array_filter($statements, fn (string $sql) => str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'document_sequences')),
        );
    }

    /** The backstop: a duplicate number cannot reach the table even so. */
    public function test_the_database_refuses_a_duplicate_purchase_order_number(): void
    {
        $project = $this->project('SJ', 'Surbana Jhons');
        $this->poNumber($project);

        $existing = PurchaseOrder::firstOrFail();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('purchase_orders')->insert([
            ...collect($existing->getAttributes())->except(['id'])->all(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_counter_is_per_project_scope_in_the_sequence_table(): void
    {
        $project = $this->project('SJ', 'Surbana Jhons');
        $this->poNumber($project);
        $this->poNumber($project);

        $this->assertDatabaseHas('document_sequences', [
            'document_type' => 'purchase_order',
            'scope_type' => 'project',
            'scope_id' => $project->id,
            'last_value' => 2,
        ]);
    }

    /* ---------------- edge cases ---------------- */

    public function test_a_project_without_a_short_code_cannot_raise_an_order(): void
    {
        $project = $this->project('SJ', 'Surbana Jhons');
        DB::table('projects')->where('id', $project->id)->update(['short_code' => null]);

        $mr = MaterialRequest::create([
            'request_no' => 'MR-'.fake()->unique()->numerify('######'),
            'project_id' => $project->id,
            'requested_by' => $this->procurement->id,
            'material_request_status_id' => MaterialRequestStatus::where('code', 'approved')->value('id'),
            'urgency_id' => Urgency::where('code', 'normal')->value('id'),
            'created_by' => $this->procurement->id,
        ]);

        $this->actingAs($this->procurement, 'api')->postJson('/api/v1/purchase-orders', [
            'material_request_id' => $mr->id,
            'vendor_id' => $this->vendor->id,
            'items' => [[
                'catalog_item_id' => CatalogItem::factory()->create()->id,
                'quantity_ordered' => 1,
                'unit_price' => 10,
            ]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    /** Documents the accepted trade-off of numbering at create. */
    public function test_a_deleted_draft_leaves_a_gap(): void
    {
        $project = $this->project('SJ', 'Surbana Jhons');

        $this->assertSame('SJ-2026-09-00001', $this->poNumber($project));
        $poId = PurchaseOrder::where('po_number', 'SJ-2026-09-00001')->value('id');

        $this->actingAs($this->procurement, 'api')
            ->deleteJson("/api/v1/purchase-orders/{$poId}")
            ->assertOk();

        // 00001 is spent, not recycled.
        $this->assertSame('SJ-2026-09-00002', $this->poNumber($project));
    }

    /**
     * Numbers minted before this change keep their old shape and stay readable —
     * the two formats share the unique index and cannot collide.
     */
    public function test_legacy_manual_numbers_are_left_alone(): void
    {
        $project = $this->project('SJ', 'Surbana Jhons');
        $this->poNumber($project);

        $legacy = PurchaseOrder::firstOrFail();
        $legacy->forceFill(['po_number' => 'PO-000001'])->save();

        $this->actingAs($this->procurement, 'api')->getJson('/api/v1/purchase-orders')
            ->assertOk()
            ->assertJsonPath('data.items.0.po_number', 'PO-000001');

        // A new order still numbers from the project's own series.
        $this->assertSame('SJ-2026-09-00002', $this->poNumber($project));
    }

    /** Other document types must be untouched by the scoped counter. */
    public function test_material_request_numbers_keep_their_old_global_format(): void
    {
        $this->assertSame('MR-000001', app(DocumentSequenceService::class)->next('material_request', 'MR'));
        $this->assertSame('MR-000002', app(DocumentSequenceService::class)->next('material_request', 'MR'));
    }
}
