<?php

namespace Tests\Feature\CatalogItem;

use App\Models\CatalogItem;
use App\Models\CatalogItemType;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Material is the only catalog item type, and it cannot be deleted.
 *
 * `labor` and `subcontractor` were removed from the seeder — the catalog
 * describes things that are bought, and typing a door as "labor" produced bad
 * data rather than useful classification.
 *
 * That makes the remaining type load-bearing: catalog_items.catalog_item_type_id
 * is NOT NULL, so deleting it would make catalog items impossible to create.
 * The is_system flag is what prevents that, and the deletion test below is
 * deliberately run against an EMPTY catalog so the pre-existing in-use guard
 * cannot mask it and pass for the wrong reason.
 */
class CatalogItemTypeSeedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole(
            $this->admin,
            Role::where('name', 'Admin')->where('guard_name', 'api')->whereNull('project_id')->firstOrFail(),
        );
    }

    public function test_material_is_the_only_seeded_type(): void
    {
        $types = CatalogItemType::pluck('code')->all();

        $this->assertSame(['material'], $types, 'the seeder must ship Material alone');
    }

    public function test_labor_and_subcontractor_are_not_seeded(): void
    {
        $this->assertNull(CatalogItemType::where('code', 'labor')->first());
        $this->assertNull(CatalogItemType::where('code', 'subcontractor')->first());
    }

    public function test_material_is_flagged_as_system_managed(): void
    {
        $this->assertTrue(CatalogItemType::where('code', 'material')->firstOrFail()->is_system);
    }

    public function test_the_lookup_endpoint_returns_only_material(): void
    {
        $items = $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/catalog-item-types')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $items);
        $this->assertSame('material', $items[0]['code']);
    }

    /**
     * Run with an EMPTY catalog on purpose. With any item present the in-use
     * guard would refuse the delete anyway, and this test would pass without
     * proving the is_system flag is wired to LookupService::delete() at all.
     */
    public function test_material_cannot_be_deleted_even_when_no_item_uses_it(): void
    {
        $material = CatalogItemType::where('code', 'material')->firstOrFail();

        $this->assertSame(0, CatalogItem::where('catalog_item_type_id', $material->id)->count());

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/v1/catalog-item-types/{$material->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This is a system-managed value and cannot be deleted.');

        $this->assertNotNull(CatalogItemType::where('code', 'material')->first(), 'it must survive the attempt');
    }

    /** is_system is server-controlled; the API must not be able to clear it. */
    public function test_the_system_flag_cannot_be_unset_through_the_api(): void
    {
        $material = CatalogItemType::where('code', 'material')->firstOrFail();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/catalog-item-types/{$material->id}", ['is_system' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_system']);

        $this->assertTrue($material->refresh()->is_system);
    }

    /** The label stays editable — only deletion and the flag are locked. */
    public function test_the_label_is_still_editable(): void
    {
        $material = CatalogItemType::where('code', 'material')->firstOrFail();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/catalog-item-types/{$material->id}", ['label' => 'Materials'])
            ->assertOk();

        $this->assertSame('Materials', $material->refresh()->label);
    }
}
