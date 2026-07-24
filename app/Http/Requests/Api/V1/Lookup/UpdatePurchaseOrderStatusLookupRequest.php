<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdatePurchaseOrderStatusLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'purchase_order_statuses';
    }

    protected function routeParam(): string
    {
        return 'purchase_order_status';
    }

    protected function supportsIsTerminal(): bool
    {
        return true;
    }
}
