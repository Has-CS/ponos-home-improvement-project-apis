<?php

namespace App\Services\ActionItems\Types;

use App\Models\ChangeOrder;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Emergency COs signed on site with no price yet — work is (or was) being done
 * with no agreed figure. Mirrors ChangeOrder::isAwaitingPricing(). Pricing is a
 * PATCH (create_change_request route) that the service allows only to a PM or
 * Admin at work_authorized.
 */
class CoNeedsPricing extends ChangeOrderActionType
{
    public function key(): string
    {
        return 'co_needs_pricing';
    }

    public function label(): string
    {
        return 'Emergency change orders waiting for a price';
    }

    public function priority(): string
    {
        return 'high';
    }

    protected function statusCode(): ?string
    {
        return 'work_authorized';
    }

    protected function waitingSinceColumn(): ?string
    {
        return 'work_authorized_at';
    }

    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'create_change_request')
            && $this->authority->isPmOrAdmin($user);
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember(ChangeOrder::query()
            ->where('change_order_status_id', $this->statuses->id('co', 'work_authorized'))
            ->whereNull('value'));
    }
}
