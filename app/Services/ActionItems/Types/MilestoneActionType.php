<?php

namespace App\Services\ActionItems\Types;

use App\Models\Milestone;
use App\Services\ActionItems\ActionItemType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Milestone queues. Reads are membership-scoped (`project.access`). */
abstract class MilestoneActionType extends ActionItemType
{
    public function subject(): string
    {
        return Milestone::class;
    }

    public function subjectType(): string
    {
        return 'milestone';
    }

    public function section(): string
    {
        return 'milestones';
    }

    protected function waitingSinceColumn(): ?string
    {
        return 'planned_date';
    }

    protected function previewWith(): array
    {
        return ['project:id,code,name', 'status:id,code'];
    }

    protected function describe(Model $item): array
    {
        return ['number' => $item->code, 'title' => $item->name, 'status' => $item->status?->code];
    }

    public function link(): array
    {
        return ['resource' => 'milestones', 'filters' => []];
    }

    /**
     * Still open: no actual date, and not in a terminal status. A milestone
     * with no status at all counts as open.
     */
    protected function whereOpen(Builder $query): Builder
    {
        $terminal = $this->statuses->ids('milestone', ['completed', 'cancelled']);

        return $query->whereNull('actual_date')
            ->where(fn ($q) => $q->whereNull('status_id')->orWhereNotIn('status_id', $terminal));
    }
}
