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
 * Embedded user blocks carry the author's designation, so the frontend can show
 * WHAT a person is, not just who.
 *
 * The role is PROJECT-scoped: the same user can be a Foreman on one project and
 * a Project Manager on another, and each log must show the role for its own
 * project. Users with no project role (an Admin, who is assigned globally and
 * never gets a project_user row) fall back to their global role.
 */
class DailyLogUserRoleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('Admin');
        $this->project = Project::factory()->create();
    }

    private function role(string $name): Role
    {
        return Role::where('name', $name)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole($user, $this->role($roleName));

        return $user;
    }

    /** Staff a user onto a project; role_id omitted inherits their global role. */
    private function staff(User $user, ?Project $project = null, ?string $roleName = null): void
    {
        $payload = ['user_id' => $user->id];

        if ($roleName !== null) {
            $payload['role_id'] = $this->role($roleName)->id;
        }

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/v1/projects/'.($project ?? $this->project)->id.'/assign-user', $payload)
            ->assertStatus(201);
    }

    private function fileLog(User $author, ?Project $project = null): int
    {
        $response = $this->actingAs($author, 'api')->postJson(
            '/api/v1/projects/'.($project ?? $this->project)->id.'/daily-logs',
            ['log_date' => now()->toDateString(), 'work_description' => 'Framed the partition walls.'],
        );

        return (int) $response->assertStatus(201)->json('data.id');
    }

    private function showRole(User $viewer, int $logId, ?Project $project = null): ?string
    {
        return $this->actingAs($viewer, 'api')
            ->getJson('/api/v1/projects/'.($project ?? $this->project)->id."/daily-logs/{$logId}")
            ->assertOk()
            ->json('data.logged_by.role');
    }

    /* ---------------- the role reflects the project assignment ---------------- */

    public function test_a_log_filed_by_a_foreman_shows_foreman(): void
    {
        $foreman = $this->userWithRole('Foreman');
        $this->staff($foreman);

        $this->assertSame('Foreman', $this->showRole($foreman, $this->fileLog($foreman)));
    }

    public function test_a_log_filed_by_a_project_manager_shows_project_manager(): void
    {
        $pm = $this->userWithRole('Project Manager');
        $this->staff($pm);

        $this->assertSame('Project Manager', $this->showRole($pm, $this->fileLog($pm)));
    }

    /**
     * The case from the request. An Admin is assigned globally —
     * assignGlobalRole() deliberately does not write project_user — so this only
     * works via the global fallback.
     */
    public function test_a_log_filed_by_an_admin_shows_admin_via_the_global_fallback(): void
    {
        $logId = $this->fileLog($this->admin);

        $this->assertDatabaseMissing('project_user', [
            'user_id' => $this->admin->id,
            'project_id' => $this->project->id,
        ]);

        $this->assertSame('Admin', $this->showRole($this->admin, $logId));
    }

    public function test_it_appears_on_the_list_endpoint_too(): void
    {
        $foreman = $this->userWithRole('Foreman');
        $this->staff($foreman);
        $this->fileLog($foreman);

        $items = $this->actingAs($foreman, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/daily-logs")
            ->assertOk()
            ->json('data.items');

        $this->assertSame('Foreman', $items[0]['logged_by']['role']);
    }

    public function test_created_by_carries_the_role_as_well(): void
    {
        $foreman = $this->userWithRole('Foreman');
        $this->staff($foreman);

        $body = $this->actingAs($foreman, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/daily-logs/{$this->fileLog($foreman)}")
            ->assertOk()
            ->json('data');

        $this->assertSame('Foreman', $body['created_by']['role']);
        $this->assertSame($foreman->id, $body['created_by']['id']);
    }

    /* ---------------- project scoping ---------------- */

    /**
     * The test that fails if this ever reads the global role instead of the
     * project one: one user, two projects, two different designations.
     */
    public function test_the_role_is_scoped_to_the_logs_own_project(): void
    {
        $other = Project::factory()->create();

        // Global role Foreman, so the naive answer everywhere would be "Foreman".
        $user = $this->userWithRole('Foreman');

        $this->staff($user, $this->project);                            // inherits Foreman
        $this->staff($user, $other, 'Project Manager');                 // explicitly PM here

        $onFirst = $this->fileLog($user, $this->project);
        $onOther = $this->fileLog($user, $other);

        $this->assertSame('Foreman', $this->showRole($user, $onFirst, $this->project));
        $this->assertSame('Project Manager', $this->showRole($user, $onOther, $other));
    }

    public function test_the_most_senior_role_wins_when_a_user_holds_two(): void
    {
        $user = $this->userWithRole('Foreman');

        $this->staff($user);                                  // Foreman
        $this->staff($user, null, 'Assistant Project Manager');

        // Assistant PM outranks Foreman in the seniority list.
        $this->assertSame('Assistant Project Manager', $this->showRole($user, $this->fileLog($user)));
    }

    public function test_a_user_with_no_role_at_all_yields_null_not_an_error(): void
    {
        $foreman = $this->userWithRole('Foreman');
        $this->staff($foreman);
        $logId = $this->fileLog($foreman);

        // Strip every role the author holds, project and global alike.
        DB::table('model_has_roles')->where('model_id', $foreman->id)->delete();
        DB::table('project_user')->where('user_id', $foreman->id)->delete();

        $body = $this->actingAs($this->admin, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/daily-logs/{$logId}")
            ->assertOk()
            ->json('data');

        $this->assertNull($body['logged_by']['role']);
        $this->assertSame($foreman->id, $body['logged_by']['id']);
    }

    /* ---------------- nothing else moved ---------------- */

    public function test_the_existing_fields_are_untouched(): void
    {
        $foreman = $this->userWithRole('Foreman');
        $this->staff($foreman);

        $this->actingAs($foreman, 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/daily-logs",
            [
                'log_date' => now()->toDateString(),
                'work_description' => 'Framed the partition walls.',
                'weather' => 'Overcast, 14C',
                'crew_count' => 6,
            ],
        )->assertStatus(201)
            ->assertJsonPath('data.work_description', 'Framed the partition walls.')
            ->assertJsonPath('data.weather', 'Overcast, 14C')
            ->assertJsonPath('data.crew_count', 6)
            ->assertJsonPath('data.has_issue', false)
            ->assertJsonPath('data.logged_by.id', $foreman->id)
            ->assertJsonPath('data.logged_by.name', trim("{$foreman->first_name} {$foreman->last_name}"))
            ->assertJsonPath('data.logged_by.role', 'Foreman');
    }

    /**
     * The role lookups must be eager-loaded, not resolved per row: the query
     * count for a page of many logs by distinct authors has to match the count
     * for a page of two.
     */
    public function test_the_list_endpoint_does_not_n_plus_one(): void
    {
        $authors = [];
        for ($i = 0; $i < 8; $i++) {
            $author = $this->userWithRole('Foreman');
            $this->staff($author);
            $authors[] = $author;
        }

        $viewer = $authors[0];

        $countQueries = function (int $logs) use ($authors, $viewer): int {
            DailyLog::query()->forceDelete();

            for ($i = 0; $i < $logs; $i++) {
                DailyLog::create([
                    'project_id' => $this->project->id,
                    'logged_by' => $authors[$i]->id,
                    'log_date' => now()->toDateString(),
                    'work_description' => "Log {$i}",
                    'has_issue' => false,
                    'created_by' => $authors[$i]->id,
                ]);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($viewer, 'api')
                ->getJson("/api/v1/projects/{$this->project->id}/daily-logs")
                ->assertOk();

            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        // Discarded: the FIRST request of the process pays one-off costs the
        // rest do not — Spatie's permission cache (array store under phpunit)
        // is cold, so an unwarmed baseline reads HIGHER than later calls and
        // makes the comparison meaningless in the wrong direction.
        $countQueries(2);

        $withTwo = $countQueries(2);
        $withEight = $countQueries(8);

        $this->assertSame(
            $withTwo,
            $withEight,
            "Query count moved from {$withTwo} (2 logs) to {$withEight} (8 logs) — "
                .'the role lookups are resolving per row instead of being eager-loaded.',
        );
    }
}
