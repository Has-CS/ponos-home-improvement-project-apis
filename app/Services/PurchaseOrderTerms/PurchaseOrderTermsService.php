<?php

namespace App\Services\PurchaseOrderTerms;

use App\Models\PurchaseOrderTerm;
use Illuminate\Database\Eloquent\Collection;

/**
 * CRUD for purchase-order Terms & Conditions, plus the rule that decides which
 * set applies to a given project.
 *
 * One entity covers both scopes (default = null project_id, override = a set
 * one), so there is no separate "settings" mechanism to keep in step.
 */
class PurchaseOrderTermsService
{
    private const WITH = ['project', 'creator'];

    /**
     * Every configured set — the company default plus each project override.
     * Default first, then by project id, so an admin screen reads top-down.
     *
     * @return Collection<int,PurchaseOrderTerm>
     */
    public function list(): Collection
    {
        return PurchaseOrderTerm::query()
            ->with(self::WITH)
            ->orderByRaw('project_id IS NOT NULL') // false (the default) first
            ->orderBy('project_id')
            ->get();
    }

    public function findDetailed(PurchaseOrderTerm $terms): PurchaseOrderTerm
    {
        return $terms->load(self::WITH);
    }

    /**
     * The terms that apply to a project: its own override if it has one,
     * otherwise the company default, otherwise none.
     *
     * Same candidate filter CatalogItemService uses to mix the global catalog
     * with a project's custom items, with an ordering added to express
     * precedence. `project_id IS NULL` evaluates to false for an override and
     * true for the default, and false sorts first in Postgres ASC — so the
     * override wins whenever both exist, in a single query with no branching.
     */
    public function resolveFor(int $projectId): ?PurchaseOrderTerm
    {
        return PurchaseOrderTerm::query()
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $projectId))
            ->orderByRaw('project_id IS NULL')
            ->first();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data, int $userId): PurchaseOrderTerm
    {
        $projectId = $data['project_id'] ?? null;

        // Checked here rather than left to the partial unique indexes so the
        // caller gets a clean 409 explaining which set already exists, instead
        // of a raw constraint violation surfacing as a 500.
        $this->assertNoExistingSet($projectId);

        return PurchaseOrderTerm::create([
            ...$data,
            'project_id' => $projectId,
            'created_by' => $userId,
        ])->fresh(self::WITH);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(PurchaseOrderTerm $terms, array $data): PurchaseOrderTerm
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
     * No in-use guard: every purchase order carries its own snapshot of the
     * text, so removing this row cannot change what any issued order says.
     * Deleting a project's override simply means that project falls back to the
     * company default from here on.
     */
    public function delete(PurchaseOrderTerm $terms): void
    {
        $terms->delete();
    }

    /** Reject a second default, or a second override for the same project. */
    private function assertNoExistingSet(?int $projectId): void
    {
        $exists = PurchaseOrderTerm::query()
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
            ? 'Default terms & conditions already exist. Edit the existing set instead.'
            : 'This project already has its own terms & conditions. Edit the existing set instead.');
    }
}
