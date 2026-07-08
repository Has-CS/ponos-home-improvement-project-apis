<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreGenderLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'genders';
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
