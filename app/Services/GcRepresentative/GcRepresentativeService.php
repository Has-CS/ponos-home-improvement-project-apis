<?php

namespace App\Services\GcRepresentative;

use App\Models\GcRepresentative;
use App\Models\ProjectGeneralContractor;
use Illuminate\Database\Eloquent\Collection;

/**
 * CRUD for the people who sign for a General Contractor on site.
 *
 * Deletes are always permitted and always soft: a signature captured for a
 * representative holds its own copy of their details, so retiring one cannot
 * alter anything already signed.
 */
class GcRepresentativeService
{
    private const WITH = ['generalContractor', 'creator'];

    /** @return Collection<int,GcRepresentative> */
    public function list(ProjectGeneralContractor $gc): Collection
    {
        // Relation is ordered by name.
        return $gc->representatives()->with(self::WITH)->get();
    }

    public function find(GcRepresentative $rep): GcRepresentative
    {
        return $rep->load(self::WITH);
    }

    public function create(ProjectGeneralContractor $gc, array $data, int $userId): GcRepresentative
    {
        $rep = $gc->representatives()->create([...$data, 'created_by' => $userId]);

        return $rep->fresh(self::WITH);
    }

    public function update(GcRepresentative $rep, array $data): GcRepresentative
    {
        $rep->fill($data)->save();

        return $rep->fresh(self::WITH);
    }

    public function delete(GcRepresentative $rep): void
    {
        $rep->delete();
    }
}
