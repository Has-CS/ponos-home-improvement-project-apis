<?php

namespace App\Services\ActionItems\Types;

use App\Models\ChangeOrder;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

/** Sent to the GC, no decision recorded yet. Oldest first, so the longest chase surfaces. */
class CoPendingGc extends ChangeOrderActionType
{
    public function key(): string
    {
        return 'co_pending_gc';
    }

    public function label(): string
    {
        return 'Change orders waiting on a GC decision';
    }

    protected function statusCode(): ?string
    {
        return 'pending_gc';
    }

    protected function waitingSinceColumn(): ?string
    {
        return 'sent_to_gc_at';
    }

    /** Recording the decision: approve_change_request + PM or Admin (ChangeOrderService::setGcDecision). */
    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'approve_change_request')
            && $this->authority->isPmOrAdmin($user);
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember(ChangeOrder::query()
            ->where('change_order_status_id', $this->statuses->id('co', 'pending_gc')));
    }
}
