<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreUrgencyLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'urgencies';
    }

    protected function codeMaxLength(): int
    {
        return 30;
    }
}
