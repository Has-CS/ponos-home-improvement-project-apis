<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdateChangeOrderStatusLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'change_order_statuses';
    }

    protected function routeParam(): string
    {
        return 'change_order_status';
    }

    protected function supportsIsTerminal(): bool
    {
        return true;
    }
}
