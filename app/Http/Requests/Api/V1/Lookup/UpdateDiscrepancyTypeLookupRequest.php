<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdateDiscrepancyTypeLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'discrepancy_types';
    }

    protected function routeParam(): string
    {
        return 'discrepancy_type';
    }
}
