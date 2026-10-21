<?php

namespace App\Services\Dashboard\Sections;

use App\Models\User;
use App\Services\Dashboard\DashboardScope;
use Illuminate\Support\Facades\DB;

/**
 * Head-count for user administrators (manage_users).
 *
 * Company-wide: counts by GLOBAL role (team 0), the role that carries
 * permissions. With a project selected: that project's team by project role,
 * from project_user (the authoritative staffing record, see ProjectRole).
 */
class PeopleSection implements DashboardSection
{
    /** Global roles grouped as "staff" in the summary buckets. */
    private const STAFF_ROLES = ['Assistant Project Manager', 'Project Coordinator', 'Site Engineer', 'Procurement'];

    public function key(): string
    {
        return 'people';
    }

    public function applies(DashboardScope $scope): bool
    {
        return $scope->can('manage_users');
    }

    public function cacheable(): bool
    {
        return false;
    }

    public function build(DashboardScope $scope): ?array
    {
        $projectId = $scope->ctx->projectId;

        return $projectId !== null ? $this->projectTeam($projectId) : $this->company($scope);
    }

    private function company(DashboardScope $scope): array
    {
        $active = $scope->statuses->id('user', 'active');
        $teamKey = config('permission.column_names.team_foreign_key', 'project_id');

        $byRole = DB::table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->join('users as u', 'u.id', '=', 'mhr.model_id')
            ->where('mhr.'.$teamKey, 0)
            ->where('mhr.model_type', (new User)->getMorphClass())
            ->whereNull('u.deleted_at')
            ->where('u.user_status_id', $active)
            ->selectRaw('r.name, count(distinct u.id) as c')
            ->groupBy('r.name')->orderBy('r.name')
            ->pluck('c', 'name')->map(fn ($c) => (int) $c)->all();

        $codes = $scope->statuses->codes('user');
        $byStatus = DB::table('users')->whereNull('deleted_at')
            ->selectRaw('user_status_id, count(*) as c')->groupBy('user_status_id')
            ->pluck('c', 'user_status_id')
            ->mapWithKeys(fn ($c, $id) => [$codes[$id] ?? (string) $id => (int) $c])->all();

        $adminRoleId = DB::table('roles')->where('name', 'Admin')->where('guard_name', 'api')->value('id');

        // Active non-admin users with no active project assignment anywhere.
        $unassigned = DB::table('users')->whereNull('users.deleted_at')
            ->where('users.user_status_id', $active)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('project_user')
                ->whereColumn('project_user.user_id', 'users.id')
                ->where('project_user.is_active', true)
                ->whereNull('project_user.deleted_at'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('model_has_roles')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.role_id', $adminRoleId ?? 0)
                ->where('model_has_roles.'.$teamKey, 0))
            ->count();

        return [
            'view' => 'company',
            'total_active' => $byStatus['active'] ?? 0,
            'active_by_role' => $byRole,
            'buckets' => [
                'admins' => $byRole['Admin'] ?? 0,
                'project_managers' => $byRole['Project Manager'] ?? 0,
                'foremen' => $byRole['Foreman'] ?? 0,
                'staff' => array_sum(array_intersect_key($byRole, array_flip(self::STAFF_ROLES))),
            ],
            'by_status' => $byStatus,
            'never_logged_in' => DB::table('users')->whereNull('deleted_at')->whereNull('last_login_at')
                ->whereIn('user_status_id', $scope->statuses->ids('user', ['active', 'invited']))->count(),
            'active_without_project' => $unassigned,
        ];
    }

    private function projectTeam(int $projectId): array
    {
        $byRole = DB::table('project_user as pu')
            ->join('roles as r', 'r.id', '=', 'pu.role_id')
            ->where('pu.project_id', $projectId)
            ->where('pu.is_active', true)
            ->whereNull('pu.deleted_at')
            ->selectRaw('r.name, count(distinct pu.user_id) as c')
            ->groupBy('r.name')->orderBy('r.name')
            ->pluck('c', 'name')->map(fn ($c) => (int) $c)->all();

        return [
            'view' => 'project',
            'project_team_size' => DB::table('project_user')->where('project_id', $projectId)
                ->where('is_active', true)->whereNull('deleted_at')->distinct()->count('user_id'),
            'project_team_by_role' => $byRole,
        ];
    }
}
