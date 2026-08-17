<?php

namespace App\Services\ChangeOrderTerms;

use App\Models\ChangeOrderTerm;
use Illuminate\Database\Eloquent\Collection;

/**
 * CRUD for change-order Payment Terms / Changes / Acceptance text, plus the rule
 * that decides which set applies to a given project.
 *
 * Deliberately the same shape as PurchaseOrderTermsService: one entity covers
 * both scopes (default = null project_id, override = a set one), so there is no
 * separate "settings" mechanism to keep in step.
 */
class ChangeOrderTermsService
{
    private const WITH = ['project', 'creator'];

    /**
     * Every configured set — the company default plus each project override.
     * Default first, then by project id, so an admin screen reads top-down.
     *
     * @return Collection<int,ChangeOrderTerm>
     */
    public function list(): Collection
    {
        return ChangeOrderTerm::query()
            ->with(self::WITH)
            ->orderByRaw('project_id IS NOT NULL') // false (the default) first
            ->orderBy('project_id')
            ->get();
    }

    public function findDetailed(ChangeOrderTerm $terms): ChangeOrderTerm
    {
        return $terms->load(self::WITH);
    }

    /**
     * The terms that apply to a project: its own override if it has one,
     * otherwise the company default, otherwise none.
     *
     * `project_id IS NULL` evaluates to false for an override and true for the
     * default, and false sorts first in Postgres ASC — so the override wins
     * whenever both exist, in a single query with no branching.
     */
    public function resolveFor(int $projectId): ?ChangeOrderTerm
    {
        return ChangeOrderTerm::query()
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $projectId))
            ->orderByRaw('project_id IS NULL')
            ->first();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data, int $userId): ChangeOrderTerm
    {
        $projectId = $data['project_id'] ?? null;

        // Checked here rather than left to the partial unique indexes so the
        // caller gets a clean 409 explaining which set already exists, instead of
        // a raw constraint violation surfacing as a 500.
        $this->assertNoExistingSet($projectId);

        return ChangeOrderTerm::create([
            ...$data,
            'project_id' => $projectId,
            'created_by' => $userId,
        ])->fresh(self::WITH);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(ChangeOrderTerm $terms, array $data): ChangeOrderTerm
    {
        // Re-scoping an existing set (default → project, or between projects)
        // can collide just as a create can.
        if (array_key_exists('project_id', $data)) {
            $target = $data['project_id'] !== null ? (int) $data['project_id'] : null;

            if ($target !== ($terms->project_id !== null ? (int) $terms->project_id : null)) {
                $this->assertNoExistingSet($target);
            }
        }

        $terms->fill($data)->save();

        return $terms->fresh(self::WITH);
    }

    /**
     * Soft-delete a terms set.
     *
     * No in-use guard: every prepared change order carries its own snapshot of
     * the text, so removing this row cannot change what any issued document says.
     * Deleting a project's override simply means that project falls back to the
     * company default from here on.
     */
    public function delete(ChangeOrderTerm $terms): void
    {
        $terms->delete();
    }

    /** Reject a second default, or a second override for the same project. */
    private function assertNoExistingSet(?int $projectId): void
    {
        $exists = ChangeOrderTerm::query()
            ->when(
                $projectId === null,
                fn ($q) => $q->whereNull('project_id'),
                fn ($q) => $q->where('project_id', $projectId),
            )
            ->exists();

        if (! $exists) {
            return;
        }

        abort(409, $projectId === null
            ? 'Default change-order terms already exist. Edit the existing set instead.'
            : 'This project already has its own change-order terms. Edit the existing set instead.');
    }
}
