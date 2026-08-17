<?php

namespace Tests\Feature\ChangeOrder;

use App\Models\ChangeOrder;
use App\Models\Project;
use App\Models\ProjectGeneralContractor;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Shared setup and request helpers for the change-order feature tests.
 *
 * Every actor gets BOTH a global role and project membership, and the
 * distinction matters:
 *
 *  - the GLOBAL role is what the `permission:` middleware and the in-service
 *    role gates (isAdmin/isPmLevel/isPmOrAdmin) read, because the whole API runs
 *    under `team.global`, which pins the Spatie registrar to scope 0;
 *  - the PROJECT membership row is what `project.access` reads, independently of
 *    Spatie scope, straight from project_user.
 *
 * A user holding only one of the two is rejected — which is the point of
 * memberOnly() and globalOnly() in the guard tests.
 */
abstract class ChangeOrderTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $foreman;

    protected User $pm;

    protected User $assistantPm;

    protected User $admin;

    protected Project $project;

    protected ProjectGeneralContractor $gc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->project = Project::factory()->create([
            'code' => 'PNS-2026-031',
            'name' => 'Ashgrove Terrace',
        ]);

        // A primary GC on the project, so change orders pick one up by default.
        // prepareDocument() refuses without one — the document is addressed to
        // the GC — so every test that reaches that step needs this. Tests that
        // exercise the no-GC path clear it explicitly.
        $this->gc = $this->makeGc(['is_primary' => true]);

        $this->foreman = $this->staffedUser('Foreman');
        $this->pm = $this->staffedUser('Project Manager');
        $this->assistantPm = $this->staffedUser('Assistant Project Manager');
        $this->admin = $this->staffedUser('Admin');
    }

    protected function role(string $name): Role
    {
        return Role::where('name', $name)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail();
    }

    /** A user with the global role AND active membership of the project. */
    protected function staffedUser(string $roleName): User
    {
        $user = $this->globalOnly($roleName);

        app(RoleAssignmentService::class)->assignProjectRole(
            $this->project,
            $user,
            $this->role($roleName),
            null,
        );

        return $user;
    }

    /**
     * A general contractor on this project (or another, via `project_id`).
     * Created directly rather than through the service so a test can set up
     * several without tripping the one-primary rule.
     *
     * @param  array<string,mixed>  $overrides
     */
    protected function makeGc(array $overrides = []): ProjectGeneralContractor
    {
        return ProjectGeneralContractor::create([
            'project_id' => $this->project->id,
            'name' => 'Kellerman Construction Group',
            'contact_name' => 'Dana Whitfield',
            'street_1' => '4400 Commerce Drive',
            'street_2' => 'Suite 210',
            'city' => 'Naperville',
            'state' => 'IL',
            'postal_code' => '60563',
            'country' => 'United States',
            'phone' => '(630) 555-0177',
            'email' => 'dana@kellermangc.test',
            ...$overrides,
        ]);
    }

    /** Holds the role (and so the permission) but is NOT staffed onto the project. */
    protected function globalOnly(string $roleName): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole($user, $this->role($roleName));

        return $user;
    }

    /* ---------------- request helpers ---------------- */

    protected function base(): string
    {
        return "/api/v1/projects/{$this->project->id}/change-orders";
    }

    /** @param array<string,mixed> $payload */
    protected function createDraftAs(User $user, array $payload = []): int
    {
        $response = $this->actingAs($user, 'api')->postJson($this->base(), [
            'title' => 'Relocate the second-floor stair core',
            'scope' => 'Demolish and rebuild the stair core three metres north of the surveyed position.',
            ...$payload,
        ]);

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    /** @param array<string,mixed> $payload */
    protected function updateAs(User $user, int $id, array $payload): TestResponse
    {
        return $this->actingAs($user, 'api')->patchJson($this->base()."/{$id}", $payload);
    }

    /** @param array<string,mixed> $payload */
    protected function actAs(User $user, int $id, string $action, array $payload = []): TestResponse
    {
        return $this->actingAs($user, 'api')->postJson($this->base()."/{$id}/{$action}", $payload);
    }

    protected function submitAs(User $u, int $id): TestResponse
    {
        return $this->actAs($u, $id, 'submit');
    }

    protected function validateAs(User $u, int $id, array $p = []): TestResponse
    {
        return $this->actAs($u, $id, 'validate', $p);
    }

    protected function approveAs(User $u, int $id, array $p = []): TestResponse
    {
        return $this->actAs($u, $id, 'approve', $p);
    }

    protected function prepareAs(User $u, int $id, array $p = []): TestResponse
    {
        return $this->actAs($u, $id, 'prepare-document', $p);
    }

    protected function counterSignAs(User $u, int $id, array $p = []): TestResponse
    {
        return $this->actAs($u, $id, 'counter-sign', $p);
    }

    protected function gcDecisionAs(User $u, int $id, array $p): TestResponse
    {
        return $this->actAs($u, $id, 'gc-decision', $p);
    }

    protected function sendBackAs(User $u, int $id, array $p): TestResponse
    {
        return $this->actAs($u, $id, 'send-back', $p);
    }

    protected function rejectAs(User $u, int $id, array $p): TestResponse
    {
        return $this->actAs($u, $id, 'reject', $p);
    }

    protected function cancelAs(User $u, int $id, array $p = []): TestResponse
    {
        return $this->actAs($u, $id, 'cancel', $p);
    }

    /* ---------------- state helpers ---------------- */

    protected function statusOf(int $id): string
    {
        return ChangeOrder::findOrFail($id)->status->code;
    }

    /**
     * Drive a foreman-raised change order up to (and including) the given status.
     * `value` is set at creation so the prepare step's 422 guard is not tripped
     * except where a test is deliberately exercising it.
     *
     * @param array<string,mixed> $payload
     */
    protected function changeOrderAt(string $status, array $payload = []): int
    {
        $id = $this->createDraftAs($this->foreman, ['value' => 18500.00, ...$payload]);

        if ($status === 'draft') {
            return $id;
        }

        $this->submitAs($this->foreman, $id)->assertOk();
        if ($status === 'pending_pm') {
            return $id;
        }

        $this->validateAs($this->pm, $id)->assertOk();
        if ($status === 'pending_admin') {
            return $id;
        }

        $this->approveAs($this->admin, $id)->assertOk();
        if ($status === 'pending_document') {
            return $id;
        }

        $this->prepareAs($this->pm, $id)->assertOk();
        if ($status === 'pending_counter_sign') {
            return $id;
        }

        $this->counterSignAs($this->admin, $id)->assertOk();
        if ($status === 'pending_gc') {
            return $id;
        }

        $this->gcDecisionAs($this->pm, $id, ['decision' => 'approved'])->assertOk();
        if ($status === 'active') {
            return $id;
        }

        $this->fail("Unsupported target status '{$status}'.");
    }
}
