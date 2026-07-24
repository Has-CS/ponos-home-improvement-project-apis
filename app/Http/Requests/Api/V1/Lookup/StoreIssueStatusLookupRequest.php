<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreIssueStatusLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'issue_statuses';
    }

    protected function supportsIsTerminal(): bool
    {
        return true;
    }
}
