<?php

namespace Tests\Feature\Dashboard;

use App\Models\Project;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two projects: the team is staffed on $projectA only; $projectB has nobody
 * on it, so anything from B showing up for a project-scoped user is a leak.
 *
 * Admin and Procurement hold their global role but are NOT staffed anywhere —
 * they must still see both projects (Admin everywhere, Procurement for
 * purchasing data), exactly as the rest of the API lets them.
 *
 * Fixtures are inserted directly rather than driven through the workflow
 * endpoints: these tests are about what the dashboard reads, and every row
 * shape matches what the services write.
 */
abstract class DashboardTestCase extends TestCase
{
    use RefreshDatabase;

    protected Project $projectA;

    protected Project $projectB;

    protected User $admin;

    protected User $pm;

    protected User $apm;

    protected User $coordinator;

    protected User $foreman;

    protected User $siteEngineer;

    protected User $procurement;

    protected int $vendorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->projectA = Project::factory()->create(['code' => 'PRJ-A', 'name' => 'Alder Court', 'budget' => 100000]);
        $this->projectB = Project::factory()->create(['code' => 'PRJ-B', 'name' => 'Birch Lane', 'budget' => 50000]);

        $this->admin = $this->globalOnly('Admin');
        $this->procurement = $this->globalOnly('Procurement');
        $this->pm = $this->staffed('Project Manager');
        $this->apm = $this->staffed('Assistant Project Manager');
        $this->coordinator = $this->staffed('Project Coordinator');
        $this->foreman = $this->staffed('Foreman');
        $this->siteEngineer = $this->staffed('Site Engineer');

        $this->vendorId = DB::table('vendors')->insertGetId([
            'name' => 'Northside Lumber', 'email' => 'orders@northside.test', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /* ---------------- actors ---------------- */

    protected function role(string $name): Role
    {
        return Role::where('name', $name)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail();
    }

    protected function globalOnly(string $roleName): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole($user, $this->role($roleName));

        return $user;
    }

    /** Global role AND an active assignment on project A. */
    protected function staffed(string $roleName, ?Project $project = null): User
    {
        $user = $this->globalOnly($roleName);
        app(RoleAssignmentService::class)->assignProjectRole($project ?? $this->projectA, $user, $this->role($roleName), null);

        return $user;
    }

    /* ---------------- requests ---------------- */

    /** @param  array<string,mixed>  $query */
    protected function dashboard(User $user, array $query = []): TestResponse
    {
        $qs = $query ? '?'.http_build_query($query) : '';

        return $this->actingAs($user, 'api')->getJson('/api/v1/dashboard'.$qs);
    }

    protected function actionItem(TestResponse $response, string $key): ?array
    {
        return collect($response->json('data.action_items.items'))->firstWhere('key', $key);
    }

    protected function actionCount(TestResponse $response, string $key): int
    {
        return (int) ($this->actionItem($response, $key)['count'] ?? 0);
    }

    /* ---------------- fixtures ---------------- */

    protected function lookupId(string $table, string $code): int
    {
        return (int) DB::table($table)->where('code', $code)->value('id');
    }

    /** @param  array<string,mixed>  $extra */
    protected function mr(Project $project, User $requester, string $status, array $extra = []): int
    {
        return DB::table('material_requests')->insertGetId([
            'request_no' => 'MR-'.uniqid(),
            'title' => 'Framing lumber',
            'project_id' => $project->id,
            'requested_by' => $requester->id,
            'material_request_status_id' => $this->lookupId('material_request_statuses', $status),
            'urgency_id' => $this->lookupId('urgencies', 'normal'),
            'created_by' => $requester->id,
            'created_at' => now(), 'updated_at' => now(),
            ...$extra,
        ]);
    }

    /** @param  array<string,mixed>  $extra */
    protected function co(Project $project, User $originator, string $status, array $extra = [], string $type = 'normal'): int
    {
        return DB::table('change_orders')->insertGetId([
            'change_order_no' => 'CO-'.uniqid(),
            'project_id' => $project->id,
            'change_order_type_id' => $this->lookupId('change_order_types', $type),
            'change_order_status_id' => $this->lookupId('change_order_statuses', $status),
            'gc_decision_id' => $this->lookupId('gc_decisions', 'pending'),
            'originator_id' => $originator->id,
            'title' => 'Relocate stair core',
            'created_by' => $originator->id,
            'created_at' => now(), 'updated_at' => now(),
            ...$extra,
        ]);
    }

    /** @param  array<string,mixed>  $extra */
    protected function po(Project $project, string $status, float $total, array $extra = []): int
    {
        return DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-'.uniqid(),
            'project_id' => $project->id,
            'vendor_id' => $this->vendorId,
            'purchase_order_status_id' => $this->lookupId('purchase_order_statuses', $status),
            'total_amount' => $total,
            'created_at' => now(), 'updated_at' => now(),
            ...$extra,
        ]);
    }

    /** @param  array<string,mixed>  $extra */
    protected function milestone(Project $project, string $plannedDate, string $status = 'not_started', array $extra = []): int
    {
        return DB::table('milestones')->insertGetId([
            'project_id' => $project->id,
            'name' => 'Rough-in inspection',
            'planned_date' => $plannedDate,
            'status_id' => $this->lookupId('milestone_statuses', $status),
            'created_at' => now(), 'updated_at' => now(),
            ...$extra,
        ]);
    }

    protected function issue(Project $project, User $raiser, string $status = 'open', ?string $severity = null, ?User $assignee = null): int
    {
        return DB::table('issues')->insertGetId([
            'project_id' => $project->id,
            'raised_by' => $raiser->id,
            'assigned_to' => $assignee?->id,
            'issue_status_id' => $this->lookupId('issue_statuses', $status),
            'title' => 'Water ingress at east wall',
            'severity' => $severity,
            'created_by' => $raiser->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function dailyLog(Project $project, User $user, string $date): int
    {
        return DB::table('daily_logs')->insertGetId([
            'project_id' => $project->id,
            'logged_by' => $user->id,
            'log_date' => $date,
            'work_description' => 'Framed second floor walls.',
            'created_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
