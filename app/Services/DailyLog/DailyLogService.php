<?php

namespace App\Services\DailyLog;

use App\Models\DailyLog;
use App\Models\Project;
use App\Models\User;
use App\Services\Attachment\AttachmentService;
use App\Services\Issue\IssueService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class DailyLogService
{
    /**
     * Most photos one daily log may carry. Referenced by both the create and
     * update FormRequests so the number lives in exactly one place — the
     * requests bound each incoming BATCH, this service enforces the post-edit
     * TOTAL, and both must agree.
     */
    public const MAX_PHOTOS = 5;

    private const LIST_WITH = ['loggedBy'];
    private const DETAIL_WITH = ['loggedBy', 'creator', 'issues.status', 'photos'];

    public function __construct(
        private readonly IssueService $issues,
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * Attach the foreman's site photos to a log.
     *
     * Each photo arrives either as an uploaded file (multipart — a phone
     * gallery picker) or as a base64 / data-URI string (a JSON body from an
     * on-device camera). Both land on the same private disk and the same
     * attachments row; only the ingest differs. A direct copy of
     * MaterialRequestService::storePhotos() — deliberately, so the two modules
     * store evidence identically.
     *
     * Only the file PATH is written to the database; the bytes go to the disk.
     *
     * @param  array<int,string|UploadedFile>  $photos
     */
    private function storePhotos(DailyLog $log, int $projectId, array $photos, int $userId): void
    {
        $meta = [
            'attachable_type' => DailyLog::class,
            'attachable_id' => $log->id,
            'project_id' => $projectId,
            'attachment_type' => 'photo',
            'directory' => 'daily-log-photos',
            'uploaded_by' => $userId,
            'captured_at' => now(),
        ];

        foreach ($photos as $photo) {
            $photo instanceof UploadedFile
                ? $this->attachments->storeUploadedImage($photo, $meta)
                : $this->attachments->storeBase64Image($photo, $meta);
        }
    }

    /**
     * Eager-loads backing the `role` on each embedded user block.
     *
     * Kept out of LIST_WITH / DETAIL_WITH because the project constraint is a
     * runtime value, which a class constant cannot hold. Loading the staffing
     * rows once per request — rather than letting ProjectRole lazy-load them
     * per record — is what keeps the list endpoint's query count flat no matter
     * how many logs, or distinct authors, are on the page.
     *
     * `roles` (Spatie, resolved under the global scope this API pins) is loaded
     * alongside for the same reason: it backs ProjectRole's global fallback,
     * which is how an Admin-authored log gets a label at all.
     *
     * @param  array<int,string>  $relations  user relations to hang these off
     * @return array<string,mixed>
     */
    private function roleEagerLoads(int $projectId, array $relations): array
    {
        $loads = [];

        foreach ($relations as $relation) {
            $loads["{$relation}.projectAssignments"] = fn ($q) => $q
                ->where('project_id', $projectId)
                ->where('is_active', true)
                ->with('role');

            $loads[] = "{$relation}.roles";
        }

        return $loads;
    }

    /**
     * The full detail load set, including the role lookups for BOTH embedded
     * user blocks the detail resource renders (`logged_by` and `created_by`).
     *
     * A method rather than a constant because the project scope is a runtime
     * value — taken from the log's own project_id, which is a column on the
     * model, so every path that returns a detail payload (show, create, update)
     * resolves roles identically.
     *
     * @return array<string,mixed>
     */
    private function detailLoads(DailyLog $log): array
    {
        return [
            ...self::DETAIL_WITH,
            ...$this->roleEagerLoads((int) $log->project_id, ['loggedBy', 'creator']),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(Project $project, array $filters): LengthAwarePaginator
    {
        // Photos are COUNTED here, never loaded. A list of logs has no use for
        // per-photo rows, and pulling them would grow the payload with every
        // log on the page; the detail endpoint carries the full set. Same split
        // MaterialRequestService::paginate() already uses.
        $query = DailyLog::query()->where('project_id', $project->id)
            ->with(self::LIST_WITH)
            ->with($this->roleEagerLoads((int) $project->id, ['loggedBy']))
            ->withCount('photos');

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
        return $log->load($this->detailLoads($log));
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

            // Inside the transaction on purpose: a photo that fails to store
            // rolls the whole submission back, rather than leaving a log
            // standing with only half the evidence the foreman attached.
            $this->storePhotos($log, (int) $project->id, $data['photos'] ?? [], $user->id);

            return $log->fresh($this->detailLoads($log));
        });
    }

    /**
     * Edit a log's fields and, in the same call, add or remove its photos.
     *
     * One transaction for the whole edit: a rejected photo must not leave the
     * text changes applied, or half the removals done. Same all-or-nothing
     * guarantee create() gives.
     */
    public function update(DailyLog $log, User $user, array $data): DailyLog
    {
        // The author/same-day window governs photos exactly as it governs the
        // text — they are edits to one dated site record, not separate things.
        $this->assertAuthorOrAdmin($log, $user);

        return DB::transaction(function () use ($log, $user, $data) {
            $this->syncPhotos($log, $user, $data);

            // Neither key is a column; fill() would ignore them anyway, but
            // dropping them here keeps that an intention rather than an accident.
            unset($data['photos'], $data['remove_photo_ids']);

            $log->fill($data)->save();

            return $log->fresh($this->detailLoads($log));
        });
    }

    /**
     * Apply an edit's photo removals and additions.
     *
     * ORDER MATTERS: removals are applied first, then the cap is checked
     * against the post-edit total. That is what lets a log already holding the
     * maximum swap photos out for new ones in a single call — checking the cap
     * before removing would reject an edit that ends up perfectly legal.
     *
     * Removal is a SOFT delete, matching every other model in this application
     * and the way RfqPdfService supersedes a stored document: the row leaves
     * every query (so the download route stops resolving it immediately) while
     * the bytes stay on disk, and a mis-tap during the edit window is
     * recoverable rather than destroying site evidence.
     *
     * @param  array<string,mixed>  $data
     */
    private function syncPhotos(DailyLog $log, User $user, array $data): void
    {
        $removeIds = array_map('intval', $data['remove_photo_ids'] ?? []);
        $additions = $data['photos'] ?? [];

        if ($removeIds !== []) {
            $owned = $log->photos()->whereIn('id', $removeIds)->get();

            // The FormRequest can only prove these ids are live attachments, not
            // that they belong to this log — without this, one log's edit could
            // delete another's photos, or a change-order signature.
            if ($owned->count() !== count(array_unique($removeIds))) {
                abort(422, 'One or more photos do not belong to this daily log.');
            }

            $owned->each->delete();
        }

        if ($additions === []) {
            return;
        }

        // Counted AFTER the removals above, so the limit applies to the state
        // the edit actually leaves behind.
        $remaining = $log->photos()->count();
        $total = $remaining + count($additions);

        if ($total > self::MAX_PHOTOS) {
            abort(422, "A daily log can carry at most ".self::MAX_PHOTOS
                ." photos; this edit would leave {$total}.");
        }

        $this->storePhotos($log, (int) $log->project_id, $additions, $user->id);
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
        if ($user->hasRole('Admin')) {
            return;
        }

        if ($log->logged_by !== $user->id) {
            abort(403, 'You can only modify your own daily log.');
        }

        // A daily log is a dated record of what happened on-site; only the
        // filer, and only on the day they filed it, may still correct it.
        // Admin retains an unrestricted override past this window.
        if (! $log->created_at->isToday()) {
            abort(403, 'Daily logs can only be edited or deleted on the day they were filed.');
        }
    }
}
