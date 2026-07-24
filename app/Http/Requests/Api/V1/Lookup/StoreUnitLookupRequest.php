<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreUnitLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'units';
    }

    protected function codeMaxLength(): int
    {
        return 20;
    }

    protected function labelMaxLength(): int
    {
        return 60;
    }
}
