<?php

namespace App\Services\ActionItems\Types;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Draft POs not yet issued. There is no PO approval step in this system —
 * this is the nearest real "waiting" state for an order.
 */
class PoDraft extends PurchaseOrderActionType
{
    public function key(): string
    {
        return 'po_draft';
    }

    public function label(): string
    {
        return 'Draft purchase orders to issue';
    }

    protected function statusCode(): ?string
    {
        return 'draft';
    }

    protected function waitingSinceColumn(): ?string
    {
        return 'created_at';
    }

    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'manage_purchase_orders');
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeProcurement(PurchaseOrder::query()
            ->where('purchase_order_status_id', $this->statuses->id('po', 'draft')));
    }
}
