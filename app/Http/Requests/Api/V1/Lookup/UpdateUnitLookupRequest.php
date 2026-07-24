<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdateUnitLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'units';
    }

    protected function routeParam(): string
    {
        return 'unit';
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
