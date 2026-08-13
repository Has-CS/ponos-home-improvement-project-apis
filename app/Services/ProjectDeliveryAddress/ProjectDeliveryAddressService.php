<?php

namespace App\Services\ProjectDeliveryAddress;

use App\Models\Project;
use App\Models\ProjectDeliveryAddress;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * CRUD for a project's ship-to destinations.
 *
 * Deliberately NOT folded into ProjectService (which still holds milestone
 * CRUD): every module built since — DailyLog, ChangeOrder, Issue — gets its own
 * service, and the primary-address transaction below is self-contained enough
 * to deserve one.
 */
class ProjectDeliveryAddressService
{
    private const WITH = ['creator'];

    /** @return Collection<int,ProjectDeliveryAddress> */
    public function list(Project $project): Collection
    {
        // Relation is already ordered primary-first, then by label.
        return $project->deliveryAddresses()->with(self::WITH)->get();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(Project $project, array $data, int $userId): ProjectDeliveryAddress
    {
        return DB::transaction(function () use ($project, $data, $userId) {
            $this->assertNotDuplicate($project->id, ProjectDeliveryAddress::fingerprintFor($data));

            $wantsPrimary = (bool) ($data['is_primary'] ?? false);

            // The FIRST address a project gets becomes its primary regardless of
            // what was asked for. Otherwise a project can sit with several
            // addresses and no default, and every PO has to pick manually — the
            // dropdown pre-selection this field exists for would never fire.
            if (! $wantsPrimary && ! $project->deliveryAddresses()->exists()) {
                $wantsPrimary = true;
            }

            if ($wantsPrimary) {
                $this->demoteCurrentPrimary($project);
            }

            $address = ProjectDeliveryAddress::create([
                ...$data,
                'project_id' => $project->id,
                'is_primary' => $wantsPrimary,
                'created_by' => $userId,
            ]);

            return $address->fresh(self::WITH);
        });
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(ProjectDeliveryAddress $address, array $data): ProjectDeliveryAddress
    {
        return DB::transaction(function () use ($address, $data) {
            // Computed from the MERGED state — stored row overlaid with what
            // this request actually sends — so a PATCH that touches one field
            // is judged on what the address will become, not on the payload
            // alone. Same approach UpdateProjectDeliveryAddressRequest uses for
            // the label-or-attention rule.
            $merged = [...$address->only([
                'label', 'attention', 'street_1', 'street_2', 'city', 'state', 'postal_code', 'country',
            ]), ...$data];

            // Excluding this row's own id: re-saving an address unchanged is
            // not a collision with itself.
            $this->assertNotDuplicate(
                (int) $address->project_id,
                ProjectDeliveryAddress::fingerprintFor($merged),
                exceptId: $address->id,
            );

            // Promotion has to demote the incumbent first or the partial unique
            // index rejects the write. Demotion is handled below instead.
            if (array_key_exists('is_primary', $data) && (bool) $data['is_primary'] === true) {
                $this->demoteCurrentPrimary($address->project, exceptId: $address->id);
            }

            // Refusing to demote the last primary keeps "a project with
            // addresses always has a default" true, which is what lets a PO be
            // created without naming one. Clearing it is still possible by
            // promoting a different address — that path demotes this one.
            if (array_key_exists('is_primary', $data)
                && (bool) $data['is_primary'] === false
                && $address->is_primary) {
                abort(422, 'Set another address as primary instead of clearing this one.');
            }

            $address->fill($data)->save();

            return $address->fresh(self::WITH);
        });
    }

    /**
     * Soft-delete an address.
     *
     * No in-use guard, unlike Vendor (which blocks deletion while rate history
     * exists). A purchase order snapshots the address onto itself, so removing
     * the row here cannot change or break any existing order — that is exactly
     * what the snapshot is for. The FK on purchase_orders keeps resolving too,
     * since a soft-deleted row is still present.
     */
    public function delete(ProjectDeliveryAddress $address): void
    {
        DB::transaction(function () use ($address) {
            $wasPrimary = $address->is_primary;
            $project = $address->project;

            // Clear the flag as well as deleting. The partial index ignores
            // soft-deleted rows, so leaving it set would not block a future
            // primary — but a restored row would silently create a second one.
            $address->forceFill(['is_primary' => false])->save();
            $address->delete();

            // Promote the next-oldest survivor so the project keeps a default.
            if ($wasPrimary) {
                $next = $project->deliveryAddresses()->orderBy('id')->first();
                $next?->forceFill(['is_primary' => true])->save();
            }
        });
    }

    /**
     * Reject an address the project already holds.
     *
     * Checked here as well as by the project_delivery_addresses_dedupe index so
     * the caller gets a usable 409 naming the row it clashed with, rather than
     * a raw constraint violation surfacing as a 500. The index remains the
     * authority under concurrency — same division of labour as the primary-flag
     * handling below and PurchaseOrderTermsService::assertNoExistingSet().
     *
     * Identity is defined in one place only: ProjectDeliveryAddress::fingerprintFor().
     */
    private function assertNotDuplicate(int $projectId, string $fingerprint, ?int $exceptId = null): void
    {
        $existing = ProjectDeliveryAddress::query()
            ->where('project_id', $projectId)
            ->where('address_fingerprint', $fingerprint)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->first();

        if (! $existing) {
            return;
        }

        // abort() cannot carry structured detail, and the id of the clashing
        // row is the one thing a client needs in order to recover — select it,
        // or edit it, instead of retrying. HttpResponseException is passed
        // through untouched by the handler in bootstrap/app.php, the same route
        // FormRequests use to emit their 422s.
        throw new HttpResponseException(ApiResponse::error(
            'This address already exists on the project.',
            409,
            ['existing_address_id' => $existing->id],
        ));
    }

    /**
     * Clear the project's existing primary, if any, so a new one can be set
     * without tripping project_delivery_addresses_one_primary.
     *
     * Row-locked for the same reason VendorRateService::addRate() locks the open
     * rate it is about to close: two concurrent promotions would otherwise both
     * read "no primary yet" and race into the index.
     */
    private function demoteCurrentPrimary(Project $project, ?int $exceptId = null): void
    {
        ProjectDeliveryAddress::where('project_id', $project->id)
            ->where('is_primary', true)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->lockForUpdate()
            ->get()
            ->each(fn (ProjectDeliveryAddress $current) => $current->forceFill(['is_primary' => false])->save());
    }
}
