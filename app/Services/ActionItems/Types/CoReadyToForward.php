<?php

namespace App\Services\ActionItems\Types;

use App\Models\ChangeOrder;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

/** Priced emergency COs still at work_authorized: forward to Admin, or send straight to the GC. */
class CoReadyToForward extends ChangeOrderActionType
{
    public function key(): string
    {
        return 'co_ready_to_forward';
    }

    public function label(): string
    {
        return 'Priced emergency change orders ready to forward or send';
    }

    protected function statusCode(): ?string
    {
        return 'work_authorized';
    }

    protected function waitingSinceColumn(): ?string
    {
        return 'priced_at';
    }

    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'approve_change_request')
            && $this->authority->isPmOrAdmin($user);
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember(ChangeOrder::query()
            ->where('change_order_status_id', $this->statuses->id('co', 'work_authorized'))
            ->whereNotNull('value'));
    }
}
