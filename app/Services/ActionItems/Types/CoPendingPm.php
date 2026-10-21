<?php

namespace App\Services\ActionItems\Types;

use App\Models\ChangeOrder;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

class CoPendingPm extends ChangeOrderActionType
{
    public function key(): string
    {
        return 'co_pending_pm';
    }

    public function label(): string
    {
        return 'Change orders awaiting your validation';
    }

    public function priority(): string
    {
        return 'high';
    }

    protected function statusCode(): ?string
    {
        return 'pending_pm';
    }

    /** ChangeOrderService::validateCo(): approve_change_request + PM-level. Admin's step is pending_admin. */
    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'approve_change_request')
            && $this->authority->isPmLevel($user)
            && ! $this->authority->isAdmin($user);
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember(ChangeOrder::query()
            ->where('change_order_status_id', $this->statuses->id('co', 'pending_pm')));
    }
}
