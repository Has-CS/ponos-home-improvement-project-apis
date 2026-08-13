<?php

namespace Tests\Feature\MaterialRequest;

use App\Models\CostCode;
use App\Models\Project;
use App\Models\TradeCategory;
use App\Models\Unit;
use App\Models\Urgency;
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
 * Shared setup + request helpers for material-request LINE ITEM feature tests.
 *
 * The actor is a Foreman, which holds create_material_request. MR write routes
 * are gated by that permission alone (no project.access), so no project_user row
 * is needed for writes; editing their OWN request satisfies
 * MaterialRequestService::assertItemsEditable(). READ routes DO sit behind
 * project.access — use joinProject() for those.
 */
abstract class MaterialRequestLineTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $foreman;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->foreman = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole($this->foreman, $this->role('Foreman'));

        $this->project = Project::factory()->create();
    }

    protected function role(string $name): Role
    {
        return Role::where('name', $name)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail();
    }

    /** Give the actor active membership on the project — required by project.access reads. */
    protected function joinProject(): void
    {
        app(RoleAssignmentService::class)->assignProjectRole(
            $this->project,
            $this->foreman,
            $this->role('Foreman'),
            null,
        );
    }

    /* ---------------- seeded lookup ids ---------------- */

    protected function costCodeId(): int
    {
        return (int) CostCode::query()->orderBy('id')->value('id');
    }

    protected function unitId(string $code): int
    {
        return (int) Unit::where('code', $code)->value('id');
    }

    protected function tradeCategoryId(string $name): int
    {
        return (int) TradeCategory::where('name', $name)->value('id');
    }

    /* ---------------- request helpers ---------------- */

    /** @param array<string,mixed> $payload */
    protected function createDraft(array $payload = []): int
    {
        $response = $this->actingAs($this->foreman, 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests",
            ['urgency_id' => Urgency::where('code', 'normal')->value('id'), ...$payload],
        );

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    /** @param array<string,mixed> $line */
    protected function addLine(int $mrId, array $line): TestResponse
    {
        return $this->actingAs($this->foreman, 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/items",
            $line,
        );
    }

    /** @param array<string,mixed> $payload */
    protected function patchLine(int $mrId, int $itemId, array $payload): TestResponse
    {
        return $this->actingAs($this->foreman, 'api')->patchJson(
            "/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/items/{$itemId}",
            $payload,
        );
    }
}
