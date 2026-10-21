<?php

namespace App\Services\ActionItems\Types;

use App\Models\Milestone;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

/** Past the planned date and still open — for those who can update milestones. */
class MilestoneOverdue extends MilestoneActionType
{
    public function key(): string
    {
        return 'milestone_overdue';
    }

    public function label(): string
    {
        return 'Overdue milestones';
    }

    public function priority(): string
    {
        return 'high';
    }

    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'manage_milestones');
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember($this->whereOpen(Milestone::query())
            ->whereDate('planned_date', '<', today()));
    }
}
