<?php

namespace App\Services\Dashboard\Sections;

use App\Models\Milestone;
use App\Services\ActionItems\ActionContext;
use App\Services\Dashboard\DashboardScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Schedule view: upcoming, overdue, completed in the period. Membership scope; everyone may read milestones. */
class MilestonesSection implements DashboardSection
{
    private const PREVIEW = 5;

    public function key(): string
    {
        return 'milestones';
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
        $st = $scope->statuses;
        $terminal = implode(',', $st->ids('milestone', ['completed', 'cancelled']));
        $open = "actual_date is null and (status_id is null or status_id not in ({$terminal}))";
        $today = today()->toDateString();
        $horizon = today()->addDays($scope->upcomingDays)->toDateString();

        $c = ActionContext::applyIds(DB::table('milestones')->whereNull('deleted_at'), $ids)
            ->selectRaw("count(*) filter (where {$open}) as open,
                count(*) filter (where {$open} and planned_date between ? and ?) as upcoming,
                count(*) filter (where {$open} and planned_date < ?) as overdue,
                count(*) filter (where actual_date between ? and ?) as completed_in_range,
                count(*) filter (where status_id = ?) as delayed,
                count(*) filter (where {$open} and responsible_user_id = ?) as mine_open",
                [$today, $horizon, $today, $scope->from->toDateString(), $scope->to->toDateString(),
                    $st->id('milestone', 'delayed'), $scope->user()->id])
            ->first();

        $openQuery = fn () => $scope->ctx->scopeMember(Milestone::query()
            ->whereNull('actual_date')
            ->where(fn ($q) => $q->whereNull('status_id')->orWhereNotIn('status_id', $st->ids('milestone', ['completed', 'cancelled']))));

        $money = $scope->seesFinancials();

        return [
            'open' => (int) $c->open,
            'upcoming' => [
                'count' => (int) $c->upcoming,
                'preview' => $this->preview($openQuery()->whereBetween('planned_date', [$today, $horizon]), $money),
            ],
            'overdue' => [
                'count' => (int) $c->overdue,
                'preview' => $this->preview($openQuery()->where('planned_date', '<', $today), $money),
            ],
            'completed_in_range' => (int) $c->completed_in_range,
            'delayed' => (int) $c->delayed,
            'mine_open' => (int) $c->mine_open,
            'next_payment_milestones' => $this->preview($openQuery()->where('is_payment_milestone', true), $money),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function preview(Builder $query, bool $money): array
    {
        return $query->with(['project:id,code,name', 'status:id,code', 'responsibleUser:id,first_name,last_name'])
            ->orderBy('planned_date')->limit(self::PREVIEW)->get()
            ->map(fn (Milestone $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'project' => $m->project ? ['id' => $m->project->id, 'code' => $m->project->code, 'name' => $m->project->name] : null,
                'planned_date' => $m->planned_date ? Carbon::parse($m->planned_date)->toDateString() : null,
                'status' => $m->status?->code,
                'responsible' => $m->responsibleUser
                    ? trim($m->responsibleUser->first_name.' '.$m->responsibleUser->last_name)
                    : $m->responsible_party_label,
                'is_payment_milestone' => (bool) $m->is_payment_milestone,
                ...($money ? ['payment_amount' => $m->payment_amount !== null ? DashboardScope::money($m->payment_amount) : null] : []),
            ])->all();
    }
}
