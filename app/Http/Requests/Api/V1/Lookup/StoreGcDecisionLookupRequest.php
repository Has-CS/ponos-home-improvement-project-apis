<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreGcDecisionLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'gc_decisions';
    }

    protected function codeMaxLength(): int
    {
        return 30;
    }

    protected function labelMaxLength(): int
    {
        return 60;
    }
}
