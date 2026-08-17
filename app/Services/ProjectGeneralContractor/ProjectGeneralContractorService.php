<?php

namespace App\Services\ProjectGeneralContractor;

use App\Models\Project;
use App\Models\ProjectGeneralContractor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CRUD for a project's General Contractors.
 *
 * The primary-flag handling is deliberately identical to
 * ProjectDeliveryAddressService: both tables guarantee at most one live primary
 * per project through a partial unique index, and both need the incumbent
 * demoted inside the same transaction before a new one is promoted.
 *
 * No duplicate/fingerprint check, unlike delivery addresses. Sites get re-entered
 * often enough to warrant one; a project has one or two GCs.
 */
class ProjectGeneralContractorService
{
    private const WITH = ['creator'];

    /** @return Collection<int,ProjectGeneralContractor> */
    public function list(Project $project): Collection
    {
        // Relation is already ordered primary-first, then by name.
        return $project->generalContractors()->with(self::WITH)->get();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(Project $project, array $data, int $userId): ProjectGeneralContractor
    {
        return DB::transaction(function () use ($project, $data, $userId) {
            $wantsPrimary = (bool) ($data['is_primary'] ?? false);

            // The FIRST GC a project gets becomes its primary regardless of what
            // was asked for. Otherwise a project can sit with several GCs and no
            // default, and every change order has to pick manually — the
            // dropdown pre-selection this field exists for would never fire.
            if (! $wantsPrimary && ! $project->generalContractors()->exists()) {
                $wantsPrimary = true;
            }

            if ($wantsPrimary) {
                $this->demoteCurrentPrimary($project);
            }

            $gc = ProjectGeneralContractor::create([
                ...$data,
                'project_id' => $project->id,
                'is_primary' => $wantsPrimary,
                'created_by' => $userId,
            ]);

            return $gc->fresh(self::WITH);
        });
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(ProjectGeneralContractor $gc, array $data): ProjectGeneralContractor
    {
        return DB::transaction(function () use ($gc, $data) {
            // Promotion has to demote the incumbent first or the partial unique
            // index rejects the write.
            if (array_key_exists('is_primary', $data) && (bool) $data['is_primary'] === true) {
                $this->demoteCurrentPrimary($gc->project, exceptId: $gc->id);
            }

            // Refusing to demote the last primary keeps "a project with GCs
            // always has a default" true, which is what lets a change order be
            // raised without naming one. Clearing it is still possible by
            // promoting a different GC — that path demotes this one.
            if (array_key_exists('is_primary', $data)
                && (bool) $data['is_primary'] === false
                && $gc->is_primary) {
                abort(422, 'Set another general contractor as primary instead of clearing this one.');
            }

            $gc->fill($data)->save();

            return $gc->fresh(self::WITH);
        });
    }

    /**
     * Soft-delete a general contractor.
     *
     * No in-use guard: a change order snapshots the GC onto itself when its
     * document is generated, so retiring the row here cannot change or break any
     * existing change order — that is exactly what the snapshot is for. The FK on
     * change_orders keeps resolving too, since a soft-deleted row is still
     * present.
     */
    public function delete(ProjectGeneralContractor $gc): void
    {
        DB::transaction(function () use ($gc) {
            $wasPrimary = $gc->is_primary;
            $project = $gc->project;

            // Clear the flag as well as deleting. The partial index ignores
            // soft-deleted rows, so leaving it set would not block a future
            // primary — but a restored row would silently create a second one.
            $gc->forceFill(['is_primary' => false])->save();
            $gc->delete();

            // Promote the next-oldest survivor so the project keeps a default.
            if ($wasPrimary) {
                $next = $project->generalContractors()->orderBy('id')->first();
                $next?->forceFill(['is_primary' => true])->save();
            }
        });
    }

    /**
     * Clear the project's existing primary, if any, so a new one can be set
     * without tripping project_general_contractors_one_primary.
     *
     * Row-locked for the same reason ProjectDeliveryAddressService locks: two
     * concurrent promotions would otherwise both read "no primary yet" and race
     * into the index.
     */
    private function demoteCurrentPrimary(Project $project, ?int $exceptId = null): void
    {
        ProjectGeneralContractor::where('project_id', $project->id)
            ->where('is_primary', true)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->lockForUpdate()
            ->get()
            ->each(fn (ProjectGeneralContractor $current) => $current->forceFill(['is_primary' => false])->save());
    }
}
