<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreChangeOrderStatusLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'change_order_statuses';
    }

    protected function supportsIsTerminal(): bool
    {
        return true;
    }
}
