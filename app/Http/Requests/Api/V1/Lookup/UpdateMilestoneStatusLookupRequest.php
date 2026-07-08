<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdateMilestoneStatusLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'milestone_statuses';
    }

    protected function routeParam(): string
    {
        return 'milestone_status';
    }

    protected function supportsIsTerminal(): bool
    {
        return true;
    }
}
