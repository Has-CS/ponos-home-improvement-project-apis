<?php

namespace App\Services\Client;

use App\Models\Client;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ClientService
{
    private const DETAIL_WITH = ['creator'];

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Client::query();

        if (! empty($filters['search'])) {
            $t = $filters['search'];
            $query->where(fn($q) => $q->where('name', 'ilike', "%{$t}%")->orWhere('email', 'ilike', "%{$t}%"));
        }

        return $query
            ->orderBy($filters['sort_by'] ?? 'created_at', $filters['sort_dir'] ?? 'desc')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function create(array $data, int $createdBy): Client
    {
        $client = Client::create([
            ...$data,
            'created_by' => $createdBy,
        ]);

        return $client->load(self::DETAIL_WITH);
    }

    public function findDetailed(Client $client): Client
    {
        return $client->load(self::DETAIL_WITH)->loadCount('projects');
    }

    public function update(Client $client, array $data): Client
    {
        $client->fill($data)->save();
        return $client->load(self::DETAIL_WITH)->loadCount('projects');
    }

    /**
     * Soft-deletes a client, unless a project still references it —
     * restrictOnDelete() only fires on a hard DELETE, so a soft-delete here
     * would otherwise leave projects pointing at a hidden client.
     */
    public function delete(Client $client): void
    {
        if ($client->projects()->exists()) {
            throw new \RuntimeException('Cannot delete a client that has projects.');
        }

        $client->delete();
    }
}
