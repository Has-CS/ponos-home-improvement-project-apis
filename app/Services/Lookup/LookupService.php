<?php

namespace App\Services\Lookup;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;

class LookupService
{
    /** @param class-string<Model> $modelClass */
    public function list(string $modelClass): Collection
    {
        $query = $modelClass::query();
        $table = (new $modelClass)->getTable();

        // Not every lookup table has sort_order (e.g. units, catalog_item_types) —
        // fall back to alphabetical by label so list() stays reusable for those.
        $query->orderBy(Schema::hasColumn($table, 'sort_order') ? 'sort_order' : 'label');

        return $query->orderBy('id')->get();
    }

    /** @param class-string<Model> $modelClass */
    public function create(string $modelClass, array $data): Model
    {
        // fresh() so DB-side defaults (sort_order, is_system) reflect
        // correctly when the caller omits them, rather than reading as null
        // on the in-memory model.
        return $modelClass::create($data)->fresh();
    }

    public function update(Model $lookup, array $data): Model
    {
        // Defense-in-depth backstop: the Form Request already rejects any
        // attempted change to code/is_terminal on a system row with a 422.
        if ($lookup->is_system) {
            unset($data['code'], $data['is_terminal']);
        }

        $lookup->fill($data)->save();

        return $lookup->fresh();
    }

    /**
     * @param \Closure(Model): bool $isInUse Returns true if $lookup is still
     *        referenced by a live (non-soft-deleted) row elsewhere.
     * @throws \RuntimeException if the row is system-protected or in use.
     */
    public function delete(Model $lookup, \Closure $isInUse): void
    {
        if ($lookup->is_system) {
            throw new \RuntimeException('This is a system-managed value and cannot be deleted.');
        }

        if ($isInUse($lookup)) {
            throw new \RuntimeException('Cannot delete: this value is still referenced by existing records.');
        }

        $lookup->delete();
    }
}
