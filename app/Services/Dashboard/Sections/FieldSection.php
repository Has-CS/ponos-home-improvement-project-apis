<?php

namespace App\Services\Dashboard\Sections;

use App\Services\ActionItems\ActionContext;
use App\Services\Dashboard\DashboardScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Daily logs and issues — what is happening on site. Membership scope. */
class FieldSection implements DashboardSection
{
    private const MISSING_LOG_LIMIT = 25;

    /** How far back the personal log streak is computed. */
    private const STREAK_LOOKBACK_DAYS = 90;

    public function key(): string
    {
        return 'field';
    }

    public function applies(DashboardScope $scope): bool
    {
        return $scope->canAny(['view_daily_log', 'submit_daily_log', 'manage_issues']);
    }

    public function cacheable(): bool
    {
        return false;
    }

    public function build(DashboardScope $scope): ?array
    {
        $out = [];

        if ($scope->can('view_daily_log')) {
            $out['daily_logs'] = $this->teamLogs($scope);
        }
        if ($scope->can('submit_daily_log') && $scope->ctx->staffedProjectIds() !== []) {
            $out['my_daily_logs'] = $this->myLogs($scope);
        }
        if ($scope->can('manage_issues')) {
            $out['issues'] = $this->issues($scope);
        }

        return $out ?: null;
    }

    private function teamLogs(DashboardScope $scope): array
    {
        $ids = $scope->ctx->memberProjectIds();

        $c = $scope->inDateRange(ActionContext::applyIds(DB::table('daily_logs')->whereNull('deleted_at'), $ids), 'log_date')
            ->selectRaw('count(*) as c, count(*) filter (where has_issue) as with_issue, count(distinct logged_by) as authors')
            ->first();

        // Active projects nobody has logged today, with when they last were.
        $missing = ActionContext::applyIds(DB::table('projects')->whereNull('projects.deleted_at'), $ids, 'projects.id')
            ->where('projects.project_status_id', $scope->statuses->id('project', 'active'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('daily_logs')
                ->whereColumn('daily_logs.project_id', 'projects.id')
                ->whereDate('daily_logs.log_date', today())
                ->whereNull('daily_logs.deleted_at'))
            ->selectRaw('projects.id, projects.code, projects.name,
                (select max(dl.log_date) from daily_logs dl where dl.project_id = projects.id and dl.deleted_at is null) as last_log_date')
            ->orderBy('projects.name');

        $missingCount = (clone $missing)->count();

        return [
            'in_range' => (int) $c->c,
            'with_issue_in_range' => (int) $c->with_issue,
            'authors_in_range' => (int) $c->authors,
            'projects_missing_log_today' => [
                'count' => $missingCount,
                'items' => $missing->limit(self::MISSING_LOG_LIMIT)->get()->map(fn ($p) => [
                    'project' => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name],
                    'last_log_date' => $p->last_log_date,
                ])->all(),
            ],
        ];
    }

    private function myLogs(DashboardScope $scope): array
    {
        $userId = $scope->user()->id;
        $staffed = $scope->ctx->staffedProjectIds();
        $mine = fn () => DB::table('daily_logs')->whereNull('deleted_at')->where('logged_by', $userId);

        $filedToday = $mine()->whereIn('project_id', $staffed)->whereDate('log_date', today())->pluck('project_id')->all();
        $projects = DB::table('projects')->whereNull('deleted_at')->whereIn('id', $staffed)
            ->where('project_status_id', $scope->statuses->id('project', 'active'))
            ->orderBy('name')->get(['id', 'code', 'name']);

        $dates = $mine()->where('log_date', '>=', today()->subDays(self::STREAK_LOOKBACK_DAYS)->toDateString())
            ->distinct()->orderByDesc('log_date')->pluck('log_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->all();

        return [
            'in_range' => $scope->inDateRange($mine(), 'log_date')->count(),
            'streak_days' => $this->streak($dates),
            'today' => $projects->map(fn ($p) => [
                'project' => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name],
                'filed' => in_array($p->id, $filedToday),
            ])->all(),
        ];
    }

    /**
     * Consecutive days with a log, counting back from today — or from
     * yesterday when today's has not been filed yet, so an unfinished day
     * does not break the streak.
     *
     * @param  array<int,string>  $dates  distinct Y-m-d, newest first
     */
    private function streak(array $dates): int
    {
        $set = array_flip($dates);
        $day = today();
        if (! isset($set[$day->toDateString()])) {
            $day = $day->subDay();
        }

        $streak = 0;
        while (isset($set[$day->toDateString()])) {
            $streak++;
            $day = $day->subDay();
        }

        return $streak;
    }

    private function issues(DashboardScope $scope): array
    {
        $base = fn () => ActionContext::applyIds(DB::table('issues')->whereNull('deleted_at'), $scope->ctx->memberProjectIds());
        $open = $scope->statuses->ids('issue', ['open', 'in_progress']);
        $userId = $scope->user()->id;

        $bySeverity = $base()->whereIn('issue_status_id', $open)
            ->selectRaw("coalesce(severity, 'unspecified') as s, count(*) as c")->groupBy('s')
            ->pluck('c', 's')->map(fn ($c) => (int) $c)->all();

        $mine = $base()->whereIn('issue_status_id', $open)
            ->selectRaw('count(*) filter (where assigned_to = ?) as assigned, count(*) filter (where raised_by = ?) as raised', [$userId, $userId])
            ->first();

        return [
            'open' => array_sum($bySeverity),
            'open_by_severity' => [
                'critical' => $bySeverity['critical'] ?? 0,
                'high' => $bySeverity['high'] ?? 0,
                'medium' => $bySeverity['medium'] ?? 0,
                'low' => $bySeverity['low'] ?? 0,
                'unspecified' => $bySeverity['unspecified'] ?? 0,
            ],
            'opened_in_range' => $scope->inRange($base(), 'created_at')->count(),
            'resolved_in_range' => $scope->inRange($base(), 'resolved_at')->count(),
            'assigned_to_me_open' => (int) $mine->assigned,
            'raised_by_me_open' => (int) $mine->raised,
        ];
    }
}
