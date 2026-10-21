<?php

namespace App\Services\ActionItems\Types;

use App\Models\Milestone;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Milestones the user is the responsible party for, overdue or due within the
 * window. A fixed window rather than the dashboard's `upcoming_days` filter, so
 * the push side (a daily notification job) can use the identical definition.
 */
class MilestoneMineDue extends MilestoneActionType
{
    public const WINDOW_DAYS = 14;

    public function key(): string
    {
        return 'milestone_mine_due';
    }

    public function label(): string
    {
        return 'Your milestones due or overdue';
    }

    public function appliesTo(User $user): bool
    {
        return true;
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember($this->whereOpen(Milestone::query())
            ->where('responsible_user_id', $ctx->user->id)
            ->whereDate('planned_date', '<=', today()->addDays(self::WINDOW_DAYS)));
    }
}
