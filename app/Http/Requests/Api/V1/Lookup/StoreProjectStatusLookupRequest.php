<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreProjectStatusLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'project_statuses';
    }

    protected function supportsIsTerminal(): bool
    {
        return true;
    }
}
