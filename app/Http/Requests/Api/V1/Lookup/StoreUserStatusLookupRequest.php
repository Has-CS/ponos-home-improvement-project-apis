<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreUserStatusLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'user_statuses';
    }
}
