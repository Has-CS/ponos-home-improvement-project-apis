<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreChangeOrderTypeLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'change_order_types';
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
