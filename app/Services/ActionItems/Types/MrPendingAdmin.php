<?php

namespace App\Services\ActionItems\Types;

use App\Models\MaterialRequest;
use App\Models\User;
use App\Services\ActionItems\ActionContext;
use Illuminate\Database\Eloquent\Builder;

class MrPendingAdmin extends MaterialRequestActionType
{
    public function key(): string
    {
        return 'mr_pending_admin';
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
        return 'pending_admin';
    }

    /** Admin step — the terminal approval. */
    public function appliesTo(User $user): bool
    {
        return $this->authority->isAdmin($user);
    }

    public function query(ActionContext $ctx): Builder
    {
        return $ctx->scopeMember(MaterialRequest::query()
            ->where('material_request_status_id', $this->statuses->id('mr', 'pending_admin')));
    }
}
