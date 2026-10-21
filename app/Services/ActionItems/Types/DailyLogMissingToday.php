<?php

namespace App\Services\ActionItems\Types;

use App\Models\Project;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use App\Services\ActionItems\ActionItemType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Active projects the user is staffed on where they have not filed today's
 * daily log. The item is the PROJECT — there is no log row to point at yet.
 *
 * Staffed scope with no Admin bypass: filing a log is a personal duty on a
 * site you are on, not something an Admin owes for every project.
 *
 * PM and Admin are left out even though they hold submit_daily_log: the daily
 * log is the field crew's report (Foreman / Site Engineer / coordinators), and
 * a PM over several sites would otherwise see a permanent "not filed" row per
 * project.
 */
class DailyLogMissingToday extends ActionItemType
{
    public function key(): string
    {
        return 'daily_log_missing_today';
    }

    public function label(): string
    {
        return 'Projects where you have not filed today\'s daily log';
    }

    public function section(): string
    {
        return 'field';
    }

    public function subject(): string
    {
        return Project::class;
    }

    public function subjectType(): string
    {
        return 'project';
    }

    protected function waitingSinceColumn(): ?string
    {
        return null;
    }

    protected function previewWith(): array
    {
        return [];
    }

    protected function projectIdOf(Model $item): ?int
    {
        return (int) $item->getKey();
    }

    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'submit_daily_log')
            && ! $this->authority->isPmOrAdmin($user);
    }

    public function query(ActionContext $ctx): Builder
    {
        $userId = $ctx->user->id;

        return $ctx->scopeStaffed(Project::query(), 'projects.id')
            ->where('project_status_id', $this->statuses->id('project', 'active'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('daily_logs')
                ->whereColumn('daily_logs.project_id', 'projects.id')
                ->where('daily_logs.logged_by', $userId)
                ->whereDate('daily_logs.log_date', today())
                ->whereNull('daily_logs.deleted_at'));
    }

    protected function describe(Model $item): array
    {
        return ['number' => $item->code, 'title' => $item->name, 'status' => 'log_not_filed'];
    }

    public function link(): array
    {
        return ['resource' => 'daily_logs', 'filters' => []];
    }

    public function payload(Model $item): array
    {
        return [
            ...parent::payload($item),
            'project' => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name],
        ];
    }
}
