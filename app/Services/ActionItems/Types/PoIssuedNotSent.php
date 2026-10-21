<?php

namespace App\Services\ActionItems\Types;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

/** Issued (document filed) but not yet sent to the vendor. */
class PoIssuedNotSent extends PurchaseOrderActionType
{
    public function key(): string
    {
        return 'po_issued_not_sent';
    }

    public function label(): string
    {
        return 'Issued purchase orders not yet sent to the vendor';
    }

    protected function statusCode(): ?string
    {
        return 'issued';
    }

    protected function waitingSinceColumn(): ?string
    {
        return 'issued_at';
    }

    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'manage_purchase_orders');
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeProcurement(PurchaseOrder::query()
            ->where('purchase_order_status_id', $this->statuses->id('po', 'issued')));
    }
}
