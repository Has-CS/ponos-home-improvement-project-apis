<?php

namespace App\Services\ActionItems\Types;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ordered, past the expected delivery date, and not fully received. Relevant
 * both to whoever chases the vendor and to whoever receives on site, so it is
 * offered to either permission (DeliveryService uses the same desk scope).
 */
class PoOverdueDelivery extends PurchaseOrderActionType
{
    /** Statuses in which goods are still expected. */
    public const OPEN_STATUSES = ['issued', 'sent', 'acknowledged', 'partially_received'];

    public function key(): string
    {
        return 'po_overdue_delivery';
    }

    public function label(): string
    {
        return 'Purchase orders past their expected delivery date';
    }

    public function priority(): string
    {
        return 'high';
    }

    protected function statusCode(): ?string
    {
        return null;
    }

    protected function waitingSinceColumn(): ?string
    {
        return 'expected_delivery_date';
    }

    public function appliesTo(User $user): bool
    {
        return $this->authority->canAny($user, ['manage_purchase_orders', 'receive_deliveries']);
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeProcurement(PurchaseOrder::query()
            ->whereIn('purchase_order_status_id', $this->statuses->ids('po', self::OPEN_STATUSES))
            ->whereDate('expected_delivery_date', '<', today()));
    }
}
