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
use Tests\TestCase;

/**
 * The former single `role:Admin|Project Manager` group split into two:
 *  - project staffing (assign-user/remove/revokeRole) now also requires the
 *    PM to be staffed on the target project (`project.access`);
 *  - RBAC catalog definition + global role grants are now Admin-only, since
 *    neither has a project to scope to in the first place.
 */
class RbacAdministrationScopeTest extends TestCase
{
    use RefreshDatabase;

    private RoleAssignmentService $rbac;

    private User $admin;
    private User $pm;

    private Project $projectA;
    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->rbac = app(RoleAssignmentService::class);

        $this->admin = $this->userWithRole('Admin');
        $this->pm = $this->userWithRole('Project Manager');

        $this->projectA = Project::factory()->create();
        $this->projectB = Project::factory()->create();

        // PM is staffed on Project A only.
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/projects/{$this->projectA->id}/assign-user", ['user_id' => $this->pm->id])
            ->assertStatus(201);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $this->rbac->assignGlobalRole($user, $this->role($roleName));

        return $user;
    }

    private function role(string $name): Role
    {
        return Role::where('name', $name)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail();
    }

    /* ---------------- Bucket 1: project staffing, scoped by project.access ---------------- */

    public function test_pm_can_assign_a_user_on_their_own_project(): void
    {
        $newMember = $this->userWithRole('Foreman');

        $this->actingAs($this->pm, 'api')
            ->postJson("/api/v1/projects/{$this->projectA->id}/assign-user", ['user_id' => $newMember->id])
            ->assertStatus(201);
    }

    public function test_pm_cannot_assign_a_user_on_a_project_they_are_not_staffed_on(): void
    {
        $newMember = $this->userWithRole('Foreman');

        $this->actingAs($this->pm, 'api')
            ->postJson("/api/v1/projects/{$this->projectB->id}/assign-user", ['user_id' => $newMember->id])
            ->assertStatus(403);
    }

    public function test_pm_cannot_remove_a_user_on_a_project_they_are_not_staffed_on(): void
    {
        $member = $this->userWithRole('Foreman');
        $this->rbac->assignProjectRole($this->projectB, $member, $this->role('Foreman'), $this->admin->id);

        $this->actingAs($this->pm, 'api')
            ->deleteJson("/api/v1/projects/{$this->projectB->id}/users/{$member->id}")
            ->assertStatus(403);
    }

    public function test_pm_cannot_revoke_a_role_on_a_project_they_are_not_staffed_on(): void
    {
        $member = $this->userWithRole('Foreman');
        $foremanRole = $this->role('Foreman');
        $this->rbac->assignProjectRole($this->projectB, $member, $foremanRole, $this->admin->id);

        $this->actingAs($this->pm, 'api')
            ->deleteJson("/api/v1/projects/{$this->projectB->id}/users/{$member->id}/roles/{$foremanRole->id}")
            ->assertStatus(403);
    }

    public function test_admin_can_manage_staffing_on_any_project(): void
    {
        $member = $this->userWithRole('Foreman');

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/projects/{$this->projectB->id}/assign-user", ['user_id' => $member->id])
            ->assertStatus(201);
    }

    /* ---------------- Bucket 2/3: Admin-only, no project to scope to ---------------- */

    public function test_pm_cannot_assign_a_global_role(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->pm, 'api')
            ->postJson("/api/v1/users/{$target->id}/assign-role", ['role_id' => $this->role('Procurement')->id])
            ->assertStatus(403);
    }

    public function test_admin_can_assign_a_global_role(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/users/{$target->id}/assign-role", ['role_id' => $this->role('Procurement')->id])
            ->assertStatus(200);
    }

    public function test_pm_cannot_revoke_a_global_role(): void
    {
        $target = $this->userWithRole('Procurement');
        $procurementRole = $this->role('Procurement');

        $this->actingAs($this->pm, 'api')
            ->deleteJson("/api/v1/users/{$target->id}/roles/{$procurementRole->id}")
            ->assertStatus(403);
    }

    public function test_pm_cannot_create_a_permission(): void
    {
        $this->actingAs($this->pm, 'api')
            ->postJson('/api/v1/permissions', ['name' => 'test_new_permission'])
            ->assertStatus(403);
    }

    public function test_admin_can_create_a_permission(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/v1/permissions', ['name' => 'test_new_permission'])
            ->assertStatus(201);
    }

    public function test_pm_cannot_create_a_role(): void
    {
        $this->actingAs($this->pm, 'api')
            ->postJson('/api/v1/roles', ['name' => 'Test New Role'])
            ->assertStatus(403);
    }

    public function test_pm_cannot_update_a_roles_permissions(): void
    {
        $this->actingAs($this->pm, 'api')
            ->putJson("/api/v1/roles/{$this->role('Foreman')->id}/permissions", [
                'permissions' => ['view_project'],
            ])
            ->assertStatus(403);
    }

    public function test_admin_can_update_a_roles_permissions(): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson("/api/v1/roles/{$this->role('Foreman')->id}/permissions", [
                'permissions' => ['view_project'],
            ])
            ->assertStatus(200);
    }
}
