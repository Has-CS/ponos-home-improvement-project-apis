<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreMilestoneStatusLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'milestone_statuses';
    }

    protected function supportsIsTerminal(): bool
    {
        return true;
    }
}
