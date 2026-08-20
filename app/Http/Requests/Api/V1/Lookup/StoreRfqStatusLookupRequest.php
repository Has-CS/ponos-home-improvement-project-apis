<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreRfqStatusLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'rfq_statuses';
    }

    protected function supportsIsTerminal(): bool
    {
        return true;
    }
}
