<?php

namespace App\Http\Requests\Api\V1\Lookup;

// Distinct from App\Http\Requests\Api\V1\Project\UpdateProjectStatusRequest,
// which validates PATCH /projects/{project}/status (an unrelated endpoint
// that changes a single project's status_id, not this lookup table's rows).
class UpdateProjectStatusLookupRequest extends UpdateLookupRequest
{
    protected function table(): string
    {
        return 'project_statuses';
    }

    protected function routeParam(): string
    {
        return 'project_status';
    }

    protected function supportsIsTerminal(): bool
    {
        return true;
    }
}
