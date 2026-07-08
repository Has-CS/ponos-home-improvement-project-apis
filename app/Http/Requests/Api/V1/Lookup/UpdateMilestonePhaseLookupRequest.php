<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdateMilestonePhaseLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'milestone_phases';
    }

    protected function routeParam(): string
    {
        return 'milestone_phase';
    }
}
