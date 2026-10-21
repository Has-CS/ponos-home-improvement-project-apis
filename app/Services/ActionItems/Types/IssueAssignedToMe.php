<?php

namespace App\Services\ActionItems\Types;

use App\Models\Issue;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

class IssueAssignedToMe extends IssueActionType
{
    public function key(): string
    {
        return 'issue_assigned_to_me';
    }

    public function label(): string
    {
        return 'Open issues assigned to you';
    }

    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'manage_issues');
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember($this->whereOpen(Issue::query())->where('assigned_to', $ctx->user->id));
    }
}
