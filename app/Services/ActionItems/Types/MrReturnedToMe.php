<?php

namespace App\Services\ActionItems\Types;

use App\Models\MaterialRequest;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

class MrReturnedToMe extends MaterialRequestActionType
{
    public function key(): string
    {
        return 'mr_returned_to_me';
    }

    public function label(): string
    {
        return 'Your material requests sent back to you';
    }

    public function priority(): string
    {
        return 'high';
    }

    protected function statusCode(): ?string
    {
        return 'sent_back_to_foreman';
    }

    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'create_material_request');
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember(MaterialRequest::query()
            ->where('material_request_status_id', $this->statuses->id('mr', 'sent_back_to_foreman'))
            ->where('requested_by', $ctx->user->id));
    }
}
