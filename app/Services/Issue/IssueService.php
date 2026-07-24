<?php

namespace App\Services\Issue;

use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class IssueService
{
    private const OPEN = 'open';
    private const RESOLVED = 'resolved';

    private const LIST_WITH = ['status', 'assignedTo', 'raisedBy'];
    private const DETAIL_WITH = ['status', 'assignedTo', 'raisedBy', 'resolvedBy'];

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(Project $project, array $filters): LengthAwarePaginator
    {
        $query = Issue::query()->where('project_id', $project->id)->with(self::LIST_WITH);

        if (! empty($filters['search'])) {
            $query->where('title', 'ilike', '%'.$filters['search'].'%');
        }
        if (! empty($filters['status_id'])) {
            $query->where('issue_status_id', $filters['status_id']);
        }
        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        return $query->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function findDetailed(Issue $issue): Issue
    {
        return $issue->load(self::DETAIL_WITH);
    }

    public function create(Project $project, array $data, int $userId): Issue
    {
        $issue = Issue::create([
            'project_id' => $project->id,
            'daily_log_id' => $data['daily_log_id'] ?? null,
            'raised_by' => $userId,
            'assigned_to' => $data['assigned_to'] ?? null,
            'issue_status_id' => $data['issue_status_id'] ?? $this->statusId(self::OPEN),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'severity' => $data['severity'] ?? null,
            'created_by' => $userId,
        ])->fresh();

        return $issue->load(self::DETAIL_WITH);
    }

    public function update(Issue $issue, array $data): Issue
    {
        $issue->fill($data)->save();
        return $issue->fresh(self::DETAIL_WITH);
    }

    /**
     * Resolve an issue. Restricted to Admin / Project Manager per the
     * "raise: any member; resolve: PM/Admin" split.
     */
    public function resolve(Issue $issue, User $user): Issue
    {
        $user->unsetRelation('roles');
        if (! $user->hasRole(['Admin', 'Project Manager'])) {
            abort(403, 'Only an administrator or project manager can resolve an issue.');
        }

        if ($issue->resolved_at !== null) {
            abort(409, 'This issue is already resolved.');
        }

        $issue->update([
            'issue_status_id' => $this->statusId(self::RESOLVED),
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ]);

        return $issue->fresh(self::DETAIL_WITH);
    }

    private function statusId(string $code): int
    {
        return IssueStatus::where('code', $code)->value('id')
            ?? abort(500, "Issue status '{$code}' is not seeded.");
    }
}
