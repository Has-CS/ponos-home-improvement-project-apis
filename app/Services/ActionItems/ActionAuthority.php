<?php

namespace App\Services\ActionItems;

use App\Models\User;
use App\Support\Concerns\ResolvesGlobalPermissions;

/**
 * "May this user act at this step?" — answered once, for both the dashboard
 * (pull: what is waiting on me) and the notification module (push: who is now
 * waiting on this item).
 *
 * The role predicates MIRROR the private step gates in MaterialRequestService
 * and ChangeOrderService (isAdmin / isPmLevel / isPmOrAdmin) and the
 * procurement-desk rule in ScopesProjectAccess. They are copied, not shared,
 * so those services stay untouched; keep them in step if a gate there changes.
 *
 * Every check runs under the GLOBAL permission scope (team 0) explicitly rather
 * than trusting ambient middleware state, because the notification module will
 * call this from queued jobs where no `team.global` middleware has run.
 * Results are memoised per user for the lifetime of this instance.
 */
class ActionAuthority
{
    use ResolvesGlobalPermissions;

    /** @var array<string,bool> */
    private array $memo = [];

    public function can(User $user, string $permission): bool
    {
        return $this->remember($user, "can:{$permission}", fn () => $user->can($permission));
    }

    /** @param  array<int,string>  $permissions */
    public function canAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(User $user): bool
    {
        return $this->hasAnyRole($user, ['Admin']);
    }

    /** Admin, Project Manager or Assistant Project Manager — mirrors the services' isPmLevel(). */
    public function isPmLevel(User $user): bool
    {
        return $this->hasAnyRole($user, ['Admin', 'Project Manager', 'Assistant Project Manager']);
    }

    /** Admin or Project Manager — mirrors the services' isPmOrAdmin(). */
    public function isPmOrAdmin(User $user): bool
    {
        return $this->hasAnyRole($user, ['Admin', 'Project Manager']);
    }

    /** Admin or Procurement — mirrors ScopesProjectAccess::isProcurementDesk(). */
    public function isProcurementDesk(User $user): bool
    {
        return $this->hasAnyRole($user, ['Admin', 'Procurement']);
    }

    /** @param  array<int,string>  $roles */
    private function hasAnyRole(User $user, array $roles): bool
    {
        return $this->remember($user, 'role:'.implode('|', $roles), fn () => $user->hasRole($roles));
    }

    private function remember(User $user, string $check, callable $resolve): bool
    {
        return $this->memo["{$user->id}:{$check}"]
            ??= (bool) $this->withGlobalTeam($resolve, $user);
    }
}
