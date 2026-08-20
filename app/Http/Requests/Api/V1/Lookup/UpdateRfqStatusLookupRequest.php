<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdateRfqStatusLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'rfq_statuses';
    }

    protected function routeParam(): string
    {
        return 'rfq_status';
    }

    protected function supportsIsTerminal(): bool
    {
        return true;
    }
}
