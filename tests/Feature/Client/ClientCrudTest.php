<?php

namespace Tests\Feature\Client;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientCrudTest extends TestCase
{
    use RefreshDatabase;

    private RoleAssignmentService $rbac;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->rbac = app(RoleAssignmentService::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $this->rbac->assignGlobalRole($user, $this->role('Admin'));

        return $user;
    }

    private function foreman(): User
    {
        $user = User::factory()->create();
        $this->rbac->assignGlobalRole($user, $this->role('Foreman'));

        return $user;
    }

    private function role(string $name): Role
    {
        return Role::where('name', $name)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail();
    }

    public function test_index_returns_paginated_list(): void
    {
        Client::factory()->count(3)->create();
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'api')->getJson('/api/v1/clients');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data.items'));
    }

    public function test_index_search_filters_by_name(): void
    {
        Client::factory()->create(['name' => 'Acme Construction']);
        Client::factory()->create(['name' => 'Zenith Builders']);
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'api')->getJson('/api/v1/clients?search=Acme');

        $response->assertStatus(200);
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('Acme Construction', $items[0]['name']);
    }

    public function test_show_returns_full_detail_with_projects_count(): void
    {
        $client = Client::factory()->create();
        Project::factory()->count(2)->create(['client_id' => $client->id]);
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'api')->getJson("/api/v1/clients/{$client->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.projects_count', 2);
    }

    public function test_admin_can_create_client(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'api')->postJson('/api/v1/clients', [
            'name'         => 'Acme Construction',
            'contact_name' => 'Jane Doe',
            'email'        => 'jane@acme.test',
            'phone'        => '555-1234',
            'address'      => '123 Main St',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Acme Construction');
        $this->assertDatabaseHas('clients', ['name' => 'Acme Construction', 'email' => 'jane@acme.test']);
    }

    public function test_create_validation_fails_without_name(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'api')->postJson('/api/v1/clients', [
            'email' => 'jane@acme.test',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_create_validation_fails_on_malformed_email(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'api')->postJson('/api/v1/clients', [
            'name'  => 'Acme Construction',
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_non_admin_cannot_create_client(): void
    {
        $foreman = $this->foreman();

        $response = $this->actingAs($foreman, 'api')->postJson('/api/v1/clients', [
            'name' => 'Acme Construction',
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_update_client(): void
    {
        $client = Client::factory()->create(['name' => 'Old Name']);
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'api')->patchJson("/api/v1/clients/{$client->id}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.name', 'New Name');
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'New Name']);
    }

    public function test_update_validation_still_applies(): void
    {
        $client = Client::factory()->create();
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'api')->patchJson("/api/v1/clients/{$client->id}", [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_admin_can_delete_client_with_no_projects(): void
    {
        $client = Client::factory()->create();
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'api')->deleteJson("/api/v1/clients/{$client->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('clients', ['id' => $client->id]);
    }

    public function test_delete_fails_with_409_when_client_has_projects(): void
    {
        $client = Client::factory()->create();
        Project::factory()->create(['client_id' => $client->id]);
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'api')->deleteJson("/api/v1/clients/{$client->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'deleted_at' => null]);
    }

    public function test_non_admin_cannot_delete_client(): void
    {
        $client = Client::factory()->create();
        $foreman = $this->foreman();

        $response = $this->actingAs($foreman, 'api')->deleteJson("/api/v1/clients/{$client->id}");

        $response->assertStatus(403);
    }
}
