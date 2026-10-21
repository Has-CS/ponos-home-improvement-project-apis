<?php

namespace App\Services\ActionItems\Types;

use App\Models\MaterialRequest;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

class MrAwaitingPo extends MaterialRequestActionType
{
    public function key(): string
    {
        return 'mr_awaiting_po';
    }

    public function label(): string
    {
        return 'Approved material requests waiting for a purchase order';
    }

    public function priority(): string
    {
        return 'medium';
    }

    protected function statusCode(): ?string
    {
        return 'approved';
    }

    /** The buyer queue — same filter as PurchaseOrderService::pendingRequests(). */
    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'manage_purchase_orders');
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeProcurement(MaterialRequest::query()
            ->where('material_request_status_id', $this->statuses->id('mr', 'approved'))
            ->where('ordering_status', '!=', 'fully_ordered'));
    }
}
