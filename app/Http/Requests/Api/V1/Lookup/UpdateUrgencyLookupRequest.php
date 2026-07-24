<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdateUrgencyLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'urgencies';
    }

    protected function routeParam(): string
    {
        return 'urgency';
    }

    protected function codeMaxLength(): int
    {
        return 30;
    }
}
