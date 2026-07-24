<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdateMaterialRequestStatusLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'material_request_statuses';
    }

    protected function routeParam(): string
    {
        return 'material_request_status';
    }

    protected function supportsIsTerminal(): bool
    {
        return true;
    }
}
