<?php

namespace Tests\Feature\DailyLog;

use App\Models\DailyLog;
use App\Models\Project;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A daily log is a dated record of what happened on-site: the author may
 * correct it only on the day they filed it; Admin keeps an unrestricted
 * override past that window; a non-author project member can never touch
 * someone else's log, on any date.
 */
class DailyLogEditWindowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $foreman;
    private User $otherForeman;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('Admin');
        $this->foreman = $this->userWithRole('Foreman');
        $this->otherForeman = $this->userWithRole('Foreman');

        $this->project = Project::factory()->create();

        $this->staff($this->foreman);
        $this->staff($this->otherForeman);
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

    private function staff(User $user): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/assign-user", ['user_id' => $user->id])
            ->assertStatus(201);
    }

    private function fileLog(User $author): DailyLog
    {
        $response = $this->actingAs($author, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/daily-logs", [
                'log_date' => now()->toDateString(),
                'work_description' => 'Framing on the west wall.',
            ]);
        $response->assertStatus(201);

        return DailyLog::findOrFail($response->json('data.id'));
    }

    private function backdate(DailyLog $log): void
    {
        DB::table('daily_logs')->where('id', $log->id)->update(['created_at' => now()->subDay()]);
    }

    public function test_author_can_edit_and_delete_a_log_filed_today(): void
    {
        $log = $this->fileLog($this->foreman);

        $this->actingAs($this->foreman, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/daily-logs/{$log->id}", ['crew_count' => 4])
            ->assertOk();

        $this->actingAs($this->foreman, 'api')
            ->deleteJson("/api/v1/projects/{$this->project->id}/daily-logs/{$log->id}")
            ->assertOk();
    }

    public function test_author_cannot_edit_a_backdated_log(): void
    {
        $log = $this->fileLog($this->foreman);
        $this->backdate($log);

        $this->actingAs($this->foreman, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/daily-logs/{$log->id}", ['crew_count' => 4])
            ->assertStatus(403);
    }

    public function test_author_cannot_delete_a_backdated_log(): void
    {
        $log = $this->fileLog($this->foreman);
        $this->backdate($log);

        $this->actingAs($this->foreman, 'api')
            ->deleteJson("/api/v1/projects/{$this->project->id}/daily-logs/{$log->id}")
            ->assertStatus(403);
    }

    public function test_admin_can_still_edit_and_delete_a_backdated_log(): void
    {
        $log = $this->fileLog($this->foreman);
        $this->backdate($log);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/daily-logs/{$log->id}", ['crew_count' => 6])
            ->assertOk();

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/v1/projects/{$this->project->id}/daily-logs/{$log->id}")
            ->assertOk();
    }

    public function test_a_different_project_member_cannot_touch_the_log_regardless_of_date(): void
    {
        $log = $this->fileLog($this->foreman);

        $this->actingAs($this->otherForeman, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/daily-logs/{$log->id}", ['crew_count' => 4])
            ->assertStatus(403);

        $this->actingAs($this->otherForeman, 'api')
            ->deleteJson("/api/v1/projects/{$this->project->id}/daily-logs/{$log->id}")
            ->assertStatus(403);
    }
}
