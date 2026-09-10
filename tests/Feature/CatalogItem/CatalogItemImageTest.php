<?php

namespace Tests\Feature\CatalogItem;

use App\Models\CatalogItem;
use App\Models\CatalogItemType;
use App\Models\Project;
use App\Models\TradeCategory;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRate;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A catalog item carries one optional product photo.
 *
 * Stored the way a user avatar is — file on the `public` disk, relative path in
 * a column, URL derived in the resources — rather than through the polymorphic
 * attachments table: catalog items are almost always global (project_id null),
 * and the authenticated download route resolves a project-less attachment to
 * Admin-only, which would hide every image from the pickers that need it.
 *
 * The image has to surface in all four shapes the item is serialized in: list,
 * detail, the material-request type-ahead, and the purchase-order picker that
 * subclasses it.
 */
class CatalogItemImageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $foreman;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('Admin');
        $this->foreman = $this->userWithRole('Foreman');
        $this->project = Project::factory()->create();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole(
            $user,
            Role::where('name', $roleName)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail(),
        );

        return $user;
    }

    private function image(string $name = 'coupling.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 600, 400);
    }

    /** @param array<string,mixed> $payload */
    private function createItem(array $payload = []): TestResponse
    {
        return $this->actingAs($this->admin, 'api')->post('/api/v1/catalog-items', [
            'trade_category_id' => TradeCategory::query()->orderBy('id')->value('id'),
            'catalog_item_type_id' => CatalogItemType::where('code', 'material')->value('id'),
            'default_unit_id' => Unit::where('code', 'ea')->value('id'),
            'name' => 'PVC coupling, 6in schedule 40',
            ...$payload,
        ]);
    }

    /* ---------------- create ---------------- */

    public function test_an_item_can_be_created_with_an_image(): void
    {
        $response = $this->createItem(['image' => $this->image()])->assertStatus(201);

        $item = CatalogItem::findOrFail($response->json('data.id'));

        $this->assertNotNull($item->image_path);
        $this->assertStringStartsWith('catalog-items/images/', $item->image_path);
        Storage::disk('public')->assertExists($item->image_path);

        // Only the path is persisted — never a URL, never bytes.
        $this->assertStringNotContainsString('http', $item->image_path);
        $this->assertStringContainsString($item->image_path, $response->json('data.image_url'));
    }

    public function test_the_image_is_optional(): void
    {
        $response = $this->createItem()->assertStatus(201);

        $this->assertNull(CatalogItem::findOrFail($response->json('data.id'))->image_path);
        $this->assertNull($response->json('data.image_url'));
    }

    /* ---------------- update ---------------- */

    public function test_an_image_can_be_added_to_an_item_that_had_none(): void
    {
        $item = CatalogItem::factory()->create();
        $this->assertNull($item->image_path);

        $this->actingAs($this->admin, 'api')
            ->post("/api/v1/catalog-items/{$item->id}", [
                '_method' => 'PATCH',
                'image' => $this->image(),
            ])
            ->assertStatus(200);

        $item->refresh();
        $this->assertNotNull($item->image_path);
        Storage::disk('public')->assertExists($item->image_path);
    }

    /** A replaced image must not leave its predecessor orphaned on disk. */
    public function test_replacing_an_image_deletes_the_superseded_file(): void
    {
        $item = CatalogItem::factory()->create();

        $this->actingAs($this->admin, 'api')->post("/api/v1/catalog-items/{$item->id}", [
            '_method' => 'PATCH',
            'image' => $this->image('first.jpg'),
        ])->assertStatus(200);

        $first = $item->refresh()->image_path;

        $this->actingAs($this->admin, 'api')->post("/api/v1/catalog-items/{$item->id}", [
            '_method' => 'PATCH',
            'image' => $this->image('second.jpg'),
        ])->assertStatus(200);

        $second = $item->refresh()->image_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_updating_other_fields_leaves_the_image_alone(): void
    {
        $item = CatalogItem::factory()->create();

        $this->actingAs($this->admin, 'api')->post("/api/v1/catalog-items/{$item->id}", [
            '_method' => 'PATCH',
            'image' => $this->image(),
        ])->assertStatus(200);

        $path = $item->refresh()->image_path;

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/catalog-items/{$item->id}", ['name' => 'Renamed item'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Renamed item');

        $this->assertSame($path, $item->refresh()->image_path);
        Storage::disk('public')->assertExists($path);
    }

    /* ---------------- validation ---------------- */

    public function test_a_non_image_file_is_rejected(): void
    {
        $this->createItem(['image' => UploadedFile::fake()->create('spec.pdf', 20, 'application/pdf')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);

        $this->assertSame(0, CatalogItem::count());
    }

    public function test_an_oversized_image_is_rejected(): void
    {
        // 3 MB, past the 2 MB (max:2048) limit.
        $this->createItem(['image' => UploadedFile::fake()->create('huge.jpg', 3 * 1024, 'image/jpeg')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);

        $this->assertSame(0, CatalogItem::count());
    }

    /* ---------------- every serialized shape ---------------- */

    public function test_the_image_url_appears_in_the_list_and_detail_responses(): void
    {
        $id = (int) $this->createItem(['image' => $this->image()])->assertStatus(201)->json('data.id');
        $path = CatalogItem::findOrFail($id)->image_path;

        $this->actingAs($this->admin, 'api')->getJson("/api/v1/catalog-items/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id);
        $this->assertStringContainsString(
            $path,
            $this->actingAs($this->admin, 'api')->getJson("/api/v1/catalog-items/{$id}")->json('data.image_url'),
        );

        $listed = collect($this->actingAs($this->admin, 'api')->getJson('/api/v1/catalog-items')->assertOk()->json('data.items'))
            ->firstWhere('id', $id);

        $this->assertStringContainsString($path, $listed['image_url']);
    }

    /**
     * The type-ahead is where the photo actually earns its place — and it is
     * reachable by a Foreman, who holds no view_pricing.
     */
    public function test_the_image_url_appears_in_the_material_request_type_ahead(): void
    {
        $id = (int) $this->createItem(['image' => $this->image()])->assertStatus(201)->json('data.id');
        $path = CatalogItem::findOrFail($id)->image_path;

        $row = collect(
            $this->actingAs($this->foreman, 'api')
                ->getJson("/api/v1/projects/{$this->project->id}/catalog-items/search?q=coupling")
                ->assertOk()
                ->json('data.items'),
        )->firstWhere('id', $id);

        $this->assertNotNull($row, 'The item should be returned by the picker.');
        $this->assertStringContainsString($path, $row['image_url']);

        // Still price-free, as that resource requires.
        $this->assertArrayNotHasKey('current_rate', $row);
        $this->assertArrayNotHasKey('has_rate', $row);
    }

    /** The PO picker subclasses the search resource — it must inherit the field. */
    public function test_the_purchase_order_picker_carries_the_image_and_its_pricing(): void
    {
        $id = (int) $this->createItem(['image' => $this->image()])->assertStatus(201)->json('data.id');
        $path = CatalogItem::findOrFail($id)->image_path;

        $vendor = Vendor::create(['name' => 'Riverside Supply Co.', 'email' => 'pat@riverside.test', 'is_active' => true]);
        VendorRate::create([
            'vendor_id' => $vendor->id,
            'catalog_item_id' => $id,
            'unit_id' => Unit::where('code', 'ea')->value('id'),
            'rate' => '12.50',
            'currency' => 'USD',
            'effective_from' => now()->subDay()->toDateString(),
            'entered_by' => $this->admin->id,   // NOT NULL on vendor_rates
        ]);

        $row = collect(
            $this->actingAs($this->admin, 'api')
                ->getJson('/api/v1/purchase-orders/catalog-items/search?'.http_build_query([
                    'q' => 'coupling',
                    'project_id' => $this->project->id,   // required by this endpoint
                    'vendor_id' => $vendor->id,
                ]))
                ->assertOk()
                ->json('data.items'),
        )->firstWhere('id', $id);

        $this->assertNotNull($row);
        $this->assertStringContainsString($path, $row['image_url']);

        // The subclass's own fields survive alongside the new one.
        $this->assertTrue($row['has_rate']);
        $this->assertSame('12.50', $row['current_rate']['rate']);
    }

    /** An item with no image renders null everywhere, never a broken value. */
    public function test_an_item_without_an_image_renders_null_in_every_shape(): void
    {
        $item = CatalogItem::factory()->create(['name' => 'Unphotographed widget']);

        $this->assertNull(
            $this->actingAs($this->admin, 'api')->getJson("/api/v1/catalog-items/{$item->id}")->json('data.image_url'),
        );

        $listed = collect($this->actingAs($this->admin, 'api')->getJson('/api/v1/catalog-items')->json('data.items'))
            ->firstWhere('id', $item->id);
        $this->assertNull($listed['image_url']);

        $searched = collect(
            $this->actingAs($this->foreman, 'api')
                ->getJson("/api/v1/projects/{$this->project->id}/catalog-items/search?q=widget")
                ->json('data.items'),
        )->firstWhere('id', $item->id);
        $this->assertNull($searched['image_url']);
    }
}
