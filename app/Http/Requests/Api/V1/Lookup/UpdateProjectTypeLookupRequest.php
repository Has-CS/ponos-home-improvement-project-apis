<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdateProjectTypeLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'project_types';
    }

    protected function routeParam(): string
    {
        return 'project_type';
    }
}
