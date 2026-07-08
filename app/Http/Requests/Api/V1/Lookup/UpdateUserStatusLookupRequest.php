<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdateUserStatusLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'user_statuses';
    }

    protected function routeParam(): string
    {
        return 'user_status';
    }
}
