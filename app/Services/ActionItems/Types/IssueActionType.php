<?php

namespace App\Services\ActionItems\Types;

use App\Models\Issue;
use App\Services\ActionItems\ActionItemType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Issue queues. Reads are membership-scoped (`project.access`). */
abstract class IssueActionType extends ActionItemType
{
    public function subject(): string
    {
        return Issue::class;
    }

    public function subjectType(): string
    {
        return 'issue';
    }

    public function section(): string
    {
        return 'field';
    }

    protected function waitingSinceColumn(): ?string
    {
        return 'created_at';
    }

    protected function previewWith(): array
    {
        return ['project:id,code,name', 'status:id,code'];
    }

    protected function describe(Model $item): array
    {
        return ['number' => null, 'title' => $item->title, 'status' => $item->status?->code];
    }

    public function link(): array
    {
        return ['resource' => 'issues', 'filters' => []];
    }

    /** Not yet resolved or closed. */
    protected function whereOpen(Builder $query): Builder
    {
        return $query->whereIn('issue_status_id', $this->statuses->ids('issue', ['open', 'in_progress']));
    }
}
