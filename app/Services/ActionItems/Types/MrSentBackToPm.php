<?php

namespace App\Services\ActionItems\Types;

use App\Models\MaterialRequest;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

class MrSentBackToPm extends MaterialRequestActionType
{
    public function key(): string
    {
        return 'mr_sent_back_to_pm';
    }

    public function label(): string
    {
        return 'Material requests sent back by Admin for resubmission';
    }

    public function priority(): string
    {
        return 'high';
    }

    protected function statusCode(): ?string
    {
        return 'sent_back_to_pm';
    }

    /** Resubmitting goes through the submit route (create_material_request) and the service requires a PM-level role. */
    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'create_material_request')
            && $this->authority->isPmLevel($user)
            && ! $this->authority->isAdmin($user);
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember(MaterialRequest::query()
            ->where('material_request_status_id', $this->statuses->id('mr', 'sent_back_to_pm')));
    }
}
