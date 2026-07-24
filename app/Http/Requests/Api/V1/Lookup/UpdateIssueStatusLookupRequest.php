<?php

namespace App\Http\Requests\Api\V1\Lookup;

class UpdateIssueStatusLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'issue_statuses';
    }

    protected function routeParam(): string
    {
        return 'issue_status';
    }

    protected function supportsIsTerminal(): bool
    {
        return true;
    }
}
