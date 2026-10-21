<?php

namespace App\Services\ActionItems;

use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Whose view is being computed, and which projects it may reach.
 *
 * Three project scopes exist in this system, and each action type and
 * dashboard section picks the one its underlying endpoints already use:
 *
 *  - member      — `project.access`: Admin sees every project, everyone else
 *                  only those they are actively staffed on. Milestones, MRs,
 *                  issues, change orders, daily logs.
 *  - procurement — ScopesProjectAccess::isProcurementDesk(): Admin and
 *                  Procurement see every project, everyone else the member
 *                  scope. Purchase orders, deliveries, the buyer's MR queue.
 *  - staffed     — projects the user is personally on, with NO Admin bypass.
 *                  For "your own duty on a site" items such as today's log.
 *
 * A selected project (dashboard filter) narrows all three to that project.
 * A null id list means "no restriction".
 */
final class ActionContext
{
    /**
     * @param  array<int,int>  $staffedIds
     */
    private function __construct(
        public readonly User $user,
        public readonly ActionAuthority $authority,
        private readonly array $staffedIds,
        private readonly bool $isAdmin,
        private readonly bool $isDesk,
        public readonly ?int $projectId,
    ) {}

    public static function for(User $user, ActionAuthority $authority, ?int $projectId = null): self
    {
        $staffed = ProjectUser::where('user_id', $user->id)
            ->where('is_active', true)
            ->distinct()
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return new self(
            $user,
            $authority,
            $staffed,
            $authority->isAdmin($user),
            $authority->isProcurementDesk($user),
            $projectId,
        );
    }

    /** @return array<int,int>|null */
    public function memberProjectIds(): ?array
    {
        return $this->narrow($this->isAdmin ? null : $this->staffedIds);
    }

    /** @return array<int,int>|null */
    public function procurementProjectIds(): ?array
    {
        return $this->narrow($this->isDesk ? null : $this->staffedIds);
    }

    /** @return array<int,int> */
    public function staffedProjectIds(): array
    {
        return $this->narrow($this->staffedIds) ?? [];
    }

    public function canSeeProject(int $projectId): bool
    {
        return $this->isAdmin || $this->isDesk || in_array($projectId, $this->staffedIds, true);
    }

    public function isAllProjects(): bool
    {
        return $this->memberProjectIds() === null;
    }

    /**
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public function scopeMember(Builder $query, string $column = 'project_id'): Builder
    {
        return self::applyIds($query, $this->memberProjectIds(), $column);
    }

    /**
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public function scopeProcurement(Builder $query, string $column = 'project_id'): Builder
    {
        return self::applyIds($query, $this->procurementProjectIds(), $column);
    }

    /**
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public function scopeStaffed(Builder $query, string $column = 'project_id'): Builder
    {
        return self::applyIds($query, $this->staffedProjectIds(), $column);
    }

    /**
     * @template T of Builder
     *
     * @param  T  $query
     * @param  array<int,int>|null  $ids
     * @return T
     */
    public static function applyIds(Builder $query, ?array $ids, string $column = 'project_id'): Builder
    {
        // Empty list → match nothing, the same idiom as ProjectService::paginate().
        return $ids === null ? $query : $query->whereIn($column, $ids ?: [0]);
    }

    /**
     * @param  array<int,int>|null  $ids
     * @return array<int,int>|null
     */
    private function narrow(?array $ids): ?array
    {
        if ($this->projectId === null) {
            return $ids;
        }

        return $ids === null || in_array($this->projectId, $ids, true) ? [$this->projectId] : [];
    }
}
