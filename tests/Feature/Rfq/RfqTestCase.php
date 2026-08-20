<?php

namespace Tests\Feature\Rfq;

use App\Models\CatalogItem;
use App\Models\TradeCategory;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Shared setup and request helpers for the RFQ feature tests.
 *
 * RFQ is top-level, not project-scoped — every actor here only needs a GLOBAL
 * role (the `permission:manage_rfqs` middleware and RfqService::isAdmin()
 * both read the global Spatie scope, which the whole API runs under via
 * `team.global`). No project membership is required, unlike ChangeOrder's
 * test base.
 */
abstract class RfqTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $pm;

    protected User $foreman;

    protected Vendor $vendor;

    protected CatalogItem $catalogItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->globalUser('Admin');
        $this->pm = $this->globalUser('Project Manager');
        $this->foreman = $this->globalUser('Foreman');

        $this->vendor = Vendor::create([
            'name' => 'Riverside Supply Co.',
            'contact_name' => 'Pat Delgado',
            'email' => 'pat@riversidesupply.test',
            'phone' => '(630) 555-0199',
            'is_active' => true,
        ]);

        $this->catalogItem = CatalogItem::factory()->create([
            'trade_category_id' => TradeCategory::where('name', 'Plumbing')->value('id'),
            'default_unit_id' => Unit::where('code', 'ea')->value('id'),
            'name' => 'PVC coupling, 6in schedule 40',
        ]);
    }

    protected function role(string $name): Role
    {
        return Role::where('name', $name)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail();
    }

    protected function globalUser(string $roleName): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole($user, $this->role($roleName));

        return $user;
    }

    /* ---------------- request helpers ---------------- */

    /** @param array<string,mixed> $payload */
    protected function createDraftAs(User $user, array $payload = []): int
    {
        $response = $this->actingAs($user, 'api')->postJson('/api/v1/rfqs', [
            'vendor_id' => $this->vendor->id,
            'title' => 'Kitchen remodel — plumbing fixtures',
            ...$payload,
        ]);

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    /** @param array<string,mixed> $payload */
    protected function addItemAs(User $user, int $rfqId, array $payload = []): TestResponse
    {
        return $this->actingAs($user, 'api')->postJson("/api/v1/rfqs/{$rfqId}/items", $payload);
    }

    /** @param array<string,mixed> $payload */
    protected function updateItemAs(User $user, int $rfqId, int $itemId, array $payload): TestResponse
    {
        return $this->actingAs($user, 'api')->patchJson("/api/v1/rfqs/{$rfqId}/items/{$itemId}", $payload);
    }

    protected function removeItemAs(User $user, int $rfqId, int $itemId): TestResponse
    {
        return $this->actingAs($user, 'api')->deleteJson("/api/v1/rfqs/{$rfqId}/items/{$itemId}");
    }

    /** @param array<string,mixed> $payload */
    protected function updateRfqAs(User $user, int $rfqId, array $payload): TestResponse
    {
        return $this->actingAs($user, 'api')->patchJson("/api/v1/rfqs/{$rfqId}", $payload);
    }

    protected function deleteRfqAs(User $user, int $rfqId): TestResponse
    {
        return $this->actingAs($user, 'api')->deleteJson("/api/v1/rfqs/{$rfqId}");
    }

    protected function submitAs(User $user, int $rfqId): TestResponse
    {
        return $this->actingAs($user, 'api')->postJson("/api/v1/rfqs/{$rfqId}/submit");
    }

    protected function showAs(User $user, int $rfqId): TestResponse
    {
        return $this->actingAs($user, 'api')->getJson("/api/v1/rfqs/{$rfqId}");
    }

    /** A draft RFQ with one catalog-linked item already on it. */
    protected function draftWithItem(User $user = null): int
    {
        $id = $this->createDraftAs($user ?? $this->pm);
        $this->addItemAs($user ?? $this->pm, $id, [
            'catalog_item_id' => $this->catalogItem->id,
            'quantity' => 25,
        ])->assertStatus(201);

        return $id;
    }
}
