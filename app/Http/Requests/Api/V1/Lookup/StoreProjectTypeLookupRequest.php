<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreProjectTypeLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'project_types';
    }
}
