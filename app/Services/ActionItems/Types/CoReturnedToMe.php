<?php

namespace App\Services\ActionItems\Types;

use App\Models\ChangeOrder;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

/** Sent back for revision; only the originator may resubmit (ChangeOrderService::submit). */
class CoReturnedToMe extends ChangeOrderActionType
{
    public function key(): string
    {
        return 'co_returned_to_me';
    }

    public function label(): string
    {
        return 'Your change orders sent back to you';
    }

    public function priority(): string
    {
        return 'high';
    }

    protected function statusCode(): ?string
    {
        return 'sent_back';
    }

    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'create_change_request');
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember(ChangeOrder::query()
            ->where('change_order_status_id', $this->statuses->id('co', 'sent_back'))
            ->where('originator_id', $ctx->user->id));
    }
}
