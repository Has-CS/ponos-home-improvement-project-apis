<?php

namespace App\Services\Project;

use App\Models\Milestone;
use App\Models\MilestoneStatus;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Support\Concerns\ScopesProjectAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    use ScopesProjectAccess;

    private const LIST_WITH   = ['client', 'type', 'status'];
    private const DETAIL_WITH = ['client', 'type', 'status', 'creator'];
    // TODO: re-add materialRequests, dailyLogs, issues once those modules exist
    private const DETAIL_COUNTS = [
        'members',
        'milestones',
        'changeOrders',
    ];

    /**
     * Paginated project list, scoped to what the user may see (A1).
     *
     * @param array<string,mixed> $filters
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = Project::query()->with(self::LIST_WITH);

        // Access scoping: admins see all; others only assigned projects.
        if (! $this->isGlobalAdmin($user)) {
            $ids = $this->accessibleProjectIds($user);
            $query->whereIn('id', $ids ?: [0]); // empty → match nothing
        }

        if (! empty($filters['status_id'])) $query->where('project_status_id', $filters['status_id']);
        if (! empty($filters['client_id'])) $query->where('client_id', $filters['client_id']);
        if (! empty($filters['type_id']))   $query->where('project_type_id', $filters['type_id']);

        if (! empty($filters['search'])) {
            $t = $filters['search'];
            $query->where(fn($q) => $q->where('name', 'ilike', "%{$t}%")->orWhere('code', 'ilike', "%{$t}%"));
        }

        return $query
            ->orderBy($filters['sort_by'] ?? 'created_at', $filters['sort_dir'] ?? 'desc')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function create(array $data, int $createdBy): Project
    {
        $statusId = $data['project_status_id']
            ?? ProjectStatus::where('code', ProjectStatus::ACTIVE)->value('id');

        $project = Project::create([
            ...$data,
            'project_status_id' => $statusId,
            'created_by'        => $createdBy,
        ]);

        return $project->load(self::DETAIL_WITH);
    }

    public function findDetailed(Project $project): Project
    {
        return $project->load(self::DETAIL_WITH)->loadCount(self::DETAIL_COUNTS);
    }

    public function update(Project $project, array $data): Project
    {
        $project->fill($data)->save();
        return $project->load(self::DETAIL_WITH)->loadCount(self::DETAIL_COUNTS);
    }

    public function updateStatus(Project $project, int $statusId): Project
    {
        $project->update(['project_status_id' => $statusId]);
        return $project->load(self::DETAIL_WITH);
    }

    public function delete(Project $project): void
    {
        $project->delete(); // soft delete
    }

    /* ---------- milestones ---------- */

    private const MILESTONE_WITH = ['phase', 'status', 'predecessor', 'responsibleUser', 'creator'];

    public function createMilestone(Project $project, array $data, int $createdBy): Milestone
    {
        $statusId = $data['status_id']
            ?? MilestoneStatus::where('code', MilestoneStatus::NOT_STARTED)->value('id');

        $milestone = $project->milestones()->create([
            ...$data,
            'status_id' => $statusId,
            'created_by' => $createdBy,
        ]);

        return $milestone->load(self::MILESTONE_WITH);
    }

    public function updateMilestone(Milestone $milestone, array $data): Milestone
    {
        $milestone->fill($data)->save();
        return $milestone->fresh()->load(self::MILESTONE_WITH);
    }

    /**
     * Soft-deletes a milestone, unless another milestone chains off it via
     * predecessor_id — restrictOnDelete() only fires on a hard DELETE, so a
     * soft-delete here would otherwise leave successors pointing at a hidden row.
     */
    public function deleteMilestone(Milestone $milestone): void
    {
        if ($milestone->successors()->exists()) {
            throw new \RuntimeException('Cannot delete a milestone that other milestones depend on as their predecessor.');
        }

        $milestone->delete();
    }
}
