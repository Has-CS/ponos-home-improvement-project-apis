<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdateChangeOrderTypeLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'change_order_types';
    }

    protected function routeParam(): string
    {
        return 'change_order_type';
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
