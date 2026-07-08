<?php

namespace Tests\Feature\Rbac;

use App\Models\Project;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProjectAssignmentTest extends TestCase
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

    private function role(string $name): Role
    {
        return Role::where('name', $name)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail();
    }

    public function test_assign_without_role_id_inherits_single_global_role(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $this->rbac->assignGlobalRole($member, $this->role('Foreman'));
        $project = Project::factory()->create();

        $response = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/projects/{$project->id}/assign-user", ['user_id' => $member->id]);

        $response->assertStatus(201)->assertJsonPath('data.role.name', 'Foreman');

        $this->assertDatabaseHas('project_user', [
            'project_id' => $project->id,
            'user_id'    => $member->id,
            'is_active'  => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($project->id);
        $member->unsetRelation('roles');
        $this->assertTrue($member->hasRole('Foreman'));
    }

    public function test_assign_without_role_id_and_no_global_role_fails(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $project = Project::factory()->create();

        $response = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/projects/{$project->id}/assign-user", ['user_id' => $member->id]);

        $response->assertStatus(422)->assertJsonFragment([
            'message' => 'This user has no default role yet. Specify a role for this project assignment.',
        ]);
    }

    public function test_assign_without_role_id_and_multiple_global_roles_fails(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $this->rbac->assignGlobalRole($member, $this->role('Foreman'));
        $this->rbac->assignGlobalRole($member, $this->role('Site Engineer'));
        $project = Project::factory()->create();

        $response = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/projects/{$project->id}/assign-user", ['user_id' => $member->id]);

        $response->assertStatus(422)->assertJsonFragment([
            'message' => 'This user has multiple default roles. Specify which one to use on this project.',
        ]);
    }

    public function test_explicit_role_id_overrides_global_role(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $this->rbac->assignGlobalRole($member, $this->role('Foreman'));
        $project = Project::factory()->create();
        $procurement = $this->role('Procurement');

        $response = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/projects/{$project->id}/assign-user", [
                'user_id' => $member->id,
                'role_id' => $procurement->id,
            ]);

        $response->assertStatus(201)->assertJsonPath('data.role.name', 'Procurement');
    }

    public function test_user_can_hold_multiple_roles_on_same_project_and_list_groups_them(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $project = Project::factory()->create();
        $foreman = $this->role('Foreman');
        $siteEngineer = $this->role('Site Engineer');

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/projects/{$project->id}/assign-user", ['user_id' => $member->id, 'role_id' => $foreman->id])
            ->assertStatus(201);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/projects/{$project->id}/assign-user", ['user_id' => $member->id, 'role_id' => $siteEngineer->id])
            ->assertStatus(201);

        $this->assertDatabaseCount('project_user', 2);

        $response = $this->actingAs($admin, 'api')->getJson("/api/v1/projects/{$project->id}/users");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertCount(2, $data[0]['roles']);

        $roleNames = collect($data[0]['roles'])->pluck('role.name')->all();
        $this->assertContains('Foreman', $roleNames);
        $this->assertContains('Site Engineer', $roleNames);
    }

    public function test_only_one_active_project_manager_per_project(): void
    {
        $admin = $this->admin();
        $pmA = User::factory()->create();
        $pmB = User::factory()->create();
        $project = Project::factory()->create();
        $pmRole = $this->role('Project Manager');

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/projects/{$project->id}/assign-user", ['user_id' => $pmA->id, 'role_id' => $pmRole->id])
            ->assertStatus(201);

        $response = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/projects/{$project->id}/assign-user", ['user_id' => $pmB->id, 'role_id' => $pmRole->id]);

        $response->assertStatus(409);
    }

    public function test_revoking_single_role_leaves_other_roles_intact(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $project = Project::factory()->create();
        $foreman = $this->role('Foreman');
        $siteEngineer = $this->role('Site Engineer');

        $this->rbac->assignProjectRole($project, $member, $foreman, $admin->id);
        $this->rbac->assignProjectRole($project, $member, $siteEngineer, $admin->id);

        $response = $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/projects/{$project->id}/users/{$member->id}/roles/{$foreman->id}");

        $response->assertStatus(200);

        $this->assertDatabaseHas('project_user', [
            'project_id' => $project->id,
            'user_id'    => $member->id,
            'role_id'    => $foreman->id,
            'is_active'  => false,
        ]);
        $this->assertDatabaseHas('project_user', [
            'project_id' => $project->id,
            'user_id'    => $member->id,
            'role_id'    => $siteEngineer->id,
            'is_active'  => true,
        ]);

        $listResponse = $this->actingAs($admin, 'api')->getJson("/api/v1/projects/{$project->id}/users");
        $data = $listResponse->json('data');
        $this->assertCount(1, $data);
        $this->assertCount(1, $data[0]['roles']);
        $this->assertSame('Site Engineer', $data[0]['roles'][0]['role']['name']);
    }

    public function test_revoking_a_role_the_user_does_not_hold_returns_404(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $project = Project::factory()->create();
        $foreman = $this->role('Foreman');

        $response = $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/projects/{$project->id}/users/{$member->id}/roles/{$foreman->id}");

        $response->assertStatus(404);
    }

    public function test_removing_user_from_project_strips_all_project_roles_but_keeps_global_role(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $this->rbac->assignGlobalRole($member, $this->role('Foreman'));

        $project = Project::factory()->create();
        $procurement = $this->role('Procurement');
        $this->rbac->assignProjectRole($project, $member, $procurement, $admin->id);

        $response = $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/projects/{$project->id}/users/{$member->id}");

        $response->assertStatus(200);

        $this->assertDatabaseHas('project_user', [
            'project_id' => $project->id,
            'user_id'    => $member->id,
            'is_active'  => false,
        ]);

        $global = $this->rbac->globalRoles($member);
        $this->assertTrue($global->pluck('name')->contains('Foreman'));
    }
}
