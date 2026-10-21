<?php

namespace App\Services\ActionItems\Types;

use App\Models\ChangeOrder;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

/** Normal COs the Admin has approved, waiting for a PM or Admin to send them to the GC. */
class CoReadyToSend extends ChangeOrderActionType
{
    public function key(): string
    {
        return 'co_ready_to_send';
    }

    public function label(): string
    {
        return 'Approved change orders ready to send to the GC';
    }

    protected function statusCode(): ?string
    {
        return 'approved';
    }

    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'approve_change_request')
            && $this->authority->isPmOrAdmin($user);
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember(ChangeOrder::query()
            ->where('change_order_status_id', $this->statuses->id('co', 'approved')));
    }
}
