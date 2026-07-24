<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StorePurchaseOrderStatusLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'purchase_order_statuses';
    }

    protected function supportsIsTerminal(): bool
    {
        return true;
    }
}
