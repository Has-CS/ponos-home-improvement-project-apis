<?php

namespace App\Services\ActionItems\Types;

use App\Models\MaterialRequest;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

class MrNeedsStructuring extends MaterialRequestActionType
{
    public function key(): string
    {
        return 'mr_needs_structuring';
    }

    public function label(): string
    {
        return 'Approved material requests whose text still needs mapping to items';
    }

    public function priority(): string
    {
        return 'medium';
    }

    protected function statusCode(): ?string
    {
        return null;
    }

    /**
     * Post-approval branch of MaterialRequestService::markStructured(): PM,
     * Admin or the procurement desk, behind approve_material_request |
     * manage_purchase_orders. Same visibility as the buyer queue.
     */
    public function appliesTo(User $user): bool
    {
        return $this->authority->canAny($user, ['approve_material_request', 'manage_purchase_orders'])
            && ($this->authority->isPmOrAdmin($user) || $this->authority->isProcurementDesk($user));
    }

    public function query(ActionContext $ctx): Builder
    {
        $postApproval = $this->statuses->ids('mr', ['approved', 'ordered', 'partially_delivered', 'delivered']);

        return $ctx->scopeProcurement(MaterialRequest::query()
            ->whereIn('material_request_status_id', $postApproval)
            ->whereNotNull('request_text')
            ->whereNull('structured_at'));
    }
}
