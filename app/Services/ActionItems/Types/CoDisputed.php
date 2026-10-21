<?php

namespace App\Services\ActionItems\Types;

use App\Models\ChangeOrder;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Work built, payment refused: an emergency CO the GC rejected AFTER the work
 * was authorised on site. Same condition as ChangeOrderService::gcQueue()'s
 * `disputed` filter. gc_rejected is terminal, so without this row these would
 * drop out of every queue.
 */
class CoDisputed extends ChangeOrderActionType
{
    public function key(): string
    {
        return 'co_disputed';
    }

    public function label(): string
    {
        return 'Emergency change orders rejected by the GC after work was done';
    }

    public function priority(): string
    {
        return 'high';
    }

    protected function statusCode(): ?string
    {
        return 'gc_rejected';
    }

    protected function waitingSinceColumn(): ?string
    {
        return 'gc_decision_at';
    }

    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'approve_change_request')
            && $this->authority->isPmOrAdmin($user);
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember(ChangeOrder::query()
            ->where('change_order_status_id', $this->statuses->id('co', 'gc_rejected'))
            ->whereNotNull('work_authorized_at'));
    }
}
