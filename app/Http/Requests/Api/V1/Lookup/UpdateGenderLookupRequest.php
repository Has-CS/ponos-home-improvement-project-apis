<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdateGenderLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'genders';
    }

    protected function routeParam(): string
    {
        return 'gender';
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
