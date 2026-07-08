<?php

namespace App\Services\Lookup;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class LookupService
{
    /** @param class-string<Model> $modelClass */
    public function list(string $modelClass): Collection
    {
        return $modelClass::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @param class-string<Model> $modelClass */
    public function create(string $modelClass, array $data): Model
    {
        return $modelClass::create($data);
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
