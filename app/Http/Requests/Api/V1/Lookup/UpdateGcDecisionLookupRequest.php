<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdateGcDecisionLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'gc_decisions';
    }

    protected function routeParam(): string
    {
        return 'gc_decision';
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
