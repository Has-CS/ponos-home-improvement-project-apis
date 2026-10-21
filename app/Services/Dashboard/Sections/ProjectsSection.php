<?php

namespace App\Services\Dashboard\Sections;

use App\Services\ActionItems\ActionContext;
use App\Services\Dashboard\DashboardScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Portfolio counts for every user (membership scope), plus a per-project
 * health card when the view is a bounded set of projects (anyone but an
 * unfiltered Admin, whose "all projects" would make the list unbounded).
 */
class ProjectsSection implements DashboardSection
{
    private const HEALTH_LIMIT = 25;

    private const ENDING_SOON_DAYS = 30;

    public function key(): string
    {
        return 'projects';
    }

    public function applies(DashboardScope $scope): bool
    {
        return true;
    }

    public function cacheable(): bool
    {
        return false;
    }

    public function build(DashboardScope $scope): ?array
    {
        $ids = $scope->ctx->memberProjectIds();
        $active = $scope->statuses->id('project', 'active');
        $today = today()->toDateString();
        $soon = today()->addDays(self::ENDING_SOON_DAYS)->toDateString();

        $base = fn () => ActionContext::applyIds(DB::table('projects')->whereNull('deleted_at'), $ids, 'id');

        $counts = $base()->selectRaw(
            'count(*) as total,
             count(*) filter (where project_status_id = ? and end_date < ?) as overdue,
             count(*) filter (where project_status_id = ? and end_date between ? and ?) as ending_soon,
             count(*) filter (where budget is null) as missing_contract_value',
            [$active, $today, $active, $today, $soon],
        )->first();

        $codes = $scope->statuses->codes('project');
        $byStatus = $base()->selectRaw('project_status_id, count(*) as c')->groupBy('project_status_id')
            ->pluck('c', 'project_status_id')
            ->mapWithKeys(fn ($c, $id) => [$codes[$id] ?? (string) $id => (int) $c])
            ->all();

        $out = [
            'total' => (int) $counts->total,
            'by_status' => $byStatus,
            'overdue' => (int) $counts->overdue,
            'ending_soon' => (int) $counts->ending_soon,
            'without_pm' => $this->withoutPm($base(), $active),
        ];

        if ($scope->seesFinancials()) {
            $out['missing_contract_value'] = (int) $counts->missing_contract_value;
        }

        if (! $scope->ctx->isAllProjects()) {
            $out['health'] = $this->health($scope, $base());
        }

        return $out;
    }

    /** Active projects with no active Project Manager on them. */
    private function withoutPm($projects, int $activeStatusId): int
    {
        $pmRoleId = Role::where('name', 'Project Manager')->where('guard_name', 'api')->value('id');

        return $projects->where('project_status_id', $activeStatusId)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('project_user')
                ->whereColumn('project_user.project_id', 'projects.id')
                ->where('project_user.role_id', $pmRoleId ?? 0)
                ->where('project_user.is_active', true)
                ->whereNull('project_user.deleted_at'))
            ->count();
    }

    /**
     * One card per project: five grouped aggregates over the same id list, so
     * the query count does not grow with the number of projects.
     *
     * @return array<int,array<string,mixed>>
     */
    private function health(DashboardScope $scope, $projects): array
    {
        $rows = $projects->orderBy('name')->limit(self::HEALTH_LIMIT)
            ->get(['id', 'code', 'name', 'project_status_id', 'end_date']);
        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('id')->all();
        $st = $scope->statuses;
        $today = today()->toDateString();
        $milestoneTerminal = implode(',', $st->ids('milestone', ['completed', 'cancelled']));
        $completedId = $st->id('milestone', 'completed');
        $openIssue = implode(',', $st->ids('issue', ['open', 'in_progress']));
        $openCo = implode(',', $st->ids('co', ['pending_pm', 'pending_admin', 'approved', 'pending_gc', 'work_authorized', 'sent_back']));

        $milestones = DB::table('milestones')->whereNull('deleted_at')->whereIn('project_id', $ids)
            ->selectRaw("project_id, count(*) as total,
                count(*) filter (where status_id = ?) as completed,
                count(*) filter (where actual_date is null
                    and (status_id is null or status_id not in ({$milestoneTerminal}))
                    and planned_date < ?) as overdue", [$completedId, $today])
            ->groupBy('project_id')->get()->keyBy('project_id');

        $issues = DB::table('issues')->whereNull('deleted_at')->whereIn('project_id', $ids)
            ->selectRaw("project_id,
                count(*) filter (where issue_status_id in ({$openIssue})) as open,
                count(*) filter (where issue_status_id in ({$openIssue}) and severity in ('high','critical')) as open_high")
            ->groupBy('project_id')->get()->keyBy('project_id');

        $lastLogs = DB::table('daily_logs')->whereNull('deleted_at')->whereIn('project_id', $ids)
            ->selectRaw('project_id, max(log_date) as last_log_date')
            ->groupBy('project_id')->pluck('last_log_date', 'project_id');

        $mrs = DB::table('material_requests')->whereNull('deleted_at')->whereIn('project_id', $ids)
            ->whereIn('material_request_status_id', $st->ids('mr', ['pending_pm', 'pending_admin', 'sent_back_to_pm']))
            ->selectRaw('project_id, count(*) as c')->groupBy('project_id')->pluck('c', 'project_id');

        $cos = DB::table('change_orders')->whereNull('deleted_at')->whereIn('project_id', $ids)
            ->whereRaw("change_order_status_id in ({$openCo})")
            ->selectRaw('project_id, count(*) as c')->groupBy('project_id')->pluck('c', 'project_id');

        $codes = $st->codes('project');

        return $rows->map(function ($p) use ($milestones, $issues, $lastLogs, $mrs, $cos, $codes) {
            $m = $milestones[$p->id] ?? null;
            $i = $issues[$p->id] ?? null;
            $total = (int) ($m->total ?? 0);
            $done = (int) ($m->completed ?? 0);
            $lastLog = $lastLogs[$p->id] ?? null;

            return [
                'project' => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name],
                'status' => $codes[$p->project_status_id] ?? null,
                'end_date' => $p->end_date,
                'milestones_total' => $total,
                'milestones_completed' => $done,
                'milestone_progress_pct' => $total > 0 ? round($done / $total * 100, 1) : null,
                'milestones_overdue' => (int) ($m->overdue ?? 0),
                'open_issues' => (int) ($i->open ?? 0),
                'open_high_issues' => (int) ($i->open_high ?? 0),
                'last_daily_log_date' => $lastLog,
                'days_since_last_log' => $lastLog ? (int) today()->diffInDays(Carbon::parse($lastLog)->startOfDay(), true) : null,
                'pending_material_requests' => (int) ($mrs[$p->id] ?? 0),
                'open_change_orders' => (int) ($cos[$p->id] ?? 0),
            ];
        })->values()->all();
    }
}
