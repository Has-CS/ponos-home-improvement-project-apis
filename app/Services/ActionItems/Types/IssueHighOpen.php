<?php

namespace App\Services\ActionItems\Types;

use App\Models\Issue;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

/** High/critical issues still open, for those who may resolve them (IssueService::resolve: PM or Admin). */
class IssueHighOpen extends IssueActionType
{
    public function key(): string
    {
        return 'issue_high_open';
    }

    public function label(): string
    {
        return 'High and critical issues still open';
    }

    public function priority(): string
    {
        return 'high';
    }

    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'manage_issues')
            && $this->authority->isPmOrAdmin($user);
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember($this->whereOpen(Issue::query())->whereIn('severity', ['high', 'critical']));
    }
}
