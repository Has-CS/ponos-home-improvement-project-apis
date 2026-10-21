<?php

namespace App\Services\ActionItems\Types;

use App\Models\MaterialRequest;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

class MrDraft extends MaterialRequestActionType
{
    public function key(): string
    {
        return 'mr_my_drafts';
    }

    public function label(): string
    {
        return 'Your draft material requests not yet submitted';
    }

    public function priority(): string
    {
        return 'low';
    }

    protected function statusCode(): ?string
    {
        return 'draft';
    }

    public function appliesTo(User $user): bool
    {
        return $this->authority->can($user, 'create_material_request');
    }

    protected function waitingSinceColumn(): ?string
    {
        return 'created_at';
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember(MaterialRequest::query()
            ->where('material_request_status_id', $this->statuses->id('mr', 'draft'))
            ->where('requested_by', $ctx->user->id));
    }
}
