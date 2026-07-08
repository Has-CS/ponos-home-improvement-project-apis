<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreMilestonePhaseLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'milestone_phases';
    }
}
