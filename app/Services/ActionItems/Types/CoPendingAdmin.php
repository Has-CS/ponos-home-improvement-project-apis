<?php

namespace App\Services\ActionItems\Types;

use App\Models\ChangeOrder;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

/** Normal COs to approve and forwarded emergency COs to send or return — both sit at pending_admin. */
class CoPendingAdmin extends ChangeOrderActionType
{
    public function key(): string
    {
        return 'co_pending_admin';
    }

    public function label(): string
    {
        return 'Change orders awaiting your review';
    }

    public function priority(): string
    {
        return 'high';
    }

    protected function statusCode(): ?string
    {
        return 'pending_admin';
    }

    public function appliesTo(User $user): bool
    {
        return $this->authority->isAdmin($user);
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember(ChangeOrder::query()
            ->where('change_order_status_id', $this->statuses->id('co', 'pending_admin')));
    }
}
