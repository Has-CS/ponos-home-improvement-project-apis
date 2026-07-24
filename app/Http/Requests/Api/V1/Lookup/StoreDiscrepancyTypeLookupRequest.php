<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreDiscrepancyTypeLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'discrepancy_types';
    }
}
