<?php

namespace App\Services\DailyLog;

use App\Models\DailyLog;
use App\Models\Project;
use App\Models\User;
use App\Services\Issue\IssueService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DailyLogService
{
    private const LIST_WITH = ['loggedBy'];
    private const DETAIL_WITH = ['loggedBy', 'creator', 'issues.status'];

    public function __construct(private readonly IssueService $issues) {}

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(Project $project, array $filters): LengthAwarePaginator
    {
        $query = DailyLog::query()->where('project_id', $project->id)->with(self::LIST_WITH);

        if (! empty($filters['date_from'])) {
            $query->whereDate('log_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('log_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['logged_by'])) {
            $query->where('logged_by', $filters['logged_by']);
        }
        if (array_key_exists('has_issue', $filters) && $filters['has_issue'] !== null) {
            $query->where('has_issue', (bool) $filters['has_issue']);
        }

        return $query->orderByDesc('log_date')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function findDetailed(DailyLog $log): DailyLog
    {
        return $log->load(self::DETAIL_WITH);
    }

    /**
     * File a daily log for the authenticated user. Optionally raises a linked
     * field issue in the same transaction (sets has_issue + issues.daily_log_id).
     */
    public function create(Project $project, array $data, User $user): DailyLog
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $log = DailyLog::create([
                'project_id' => $project->id,
                'logged_by' => $user->id,
                'log_date' => $data['log_date'],
                'work_description' => $data['work_description'],
                'weather' => $data['weather'] ?? null,
                'crew_count' => $data['crew_count'] ?? null,
                'has_issue' => false,
                'created_by' => $user->id,
            ]);

            if (! empty($data['issue'])) {
                // Raising the linked issue requires the issue-management capability.
                if (! $user->can('manage_issues')) {
                    abort(403, 'You do not have permission to raise an issue from a daily log.');
                }

                $this->issues->create($project, [
                    'title' => $data['issue']['title'],
                    'description' => $data['issue']['description'] ?? null,
                    'severity' => $data['issue']['severity'] ?? null,
                    'assigned_to' => $data['issue']['assigned_to'] ?? null,
                    'daily_log_id' => $log->id,
                ], $user->id);

                $log->update(['has_issue' => true]);
            }

            return $log->fresh(self::DETAIL_WITH);
        });
    }

    public function update(DailyLog $log, User $user, array $data): DailyLog
    {
        $this->assertAuthorOrAdmin($log, $user);
        $log->fill($data)->save();

        return $log->fresh(self::DETAIL_WITH);
    }

    public function delete(DailyLog $log, User $user): void
    {
        $this->assertAuthorOrAdmin($log, $user);
        // Soft delete — any linked issues stay intact (daily_log_id is nullable
        // and no FK-restrict fires on a soft delete); they simply reference a
        // hidden log.
        $log->delete();
    }

    private function assertAuthorOrAdmin(DailyLog $log, User $user): void
    {
        $user->unsetRelation('roles');
        if ($log->logged_by !== $user->id && ! $user->hasRole('Admin')) {
            abort(403, 'You can only modify your own daily log.');
        }
    }
}
