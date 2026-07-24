<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreMaterialRequestStatusLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'material_request_statuses';
    }

    protected function supportsIsTerminal(): bool
    {
        return true;
    }
}
