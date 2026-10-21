<?php

namespace App\Services\ActionItems\Types;

use App\Models\MaterialRequest;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

class MrPendingPm extends MaterialRequestActionType
{
    public function key(): string
    {
        return 'mr_pending_pm';
    }

    public function label(): string
    {
        return 'Material requests awaiting your approval';
    }

    public function priority(): string
    {
        return 'high';
    }

    protected function statusCode(): ?string
    {
        return 'pending_pm';
    }

    /**
     * PM step. approve_material_request gets past the route; the service then
     * demands a PM-level role and refuses the requester. Admin is left out on
     * purpose: their step is pending_admin, and the PM-step backlog is visible
     * to them in the procurement status breakdown instead.
     */
    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'approve_material_request')
            && $this->authority->isPmLevel($user)
            && ! $this->authority->isAdmin($user);
    }

    public function query(ActionContext $ctx): Builder
    {
        $query = MaterialRequest::query()
            ->where('material_request_status_id', $this->statuses->id('mr', 'pending_pm'))
            ->where('requested_by', '!=', $ctx->user->id);

        return $ctx->scopeMember($query);
    }
}
