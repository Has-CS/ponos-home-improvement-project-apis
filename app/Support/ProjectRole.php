<?php

namespace App\Support;

use App\Models\User;

/**
 * "What is this person, on this project?" — the designation printed beside a
 * user in an API response.
 *
 * Roles in this system are project-scoped (Spatie teams, team key = project_id),
 * so the same user can be a Foreman on one project and a Project Manager on
 * another. A response embedding a user therefore has to resolve the role
 * against the project THAT RECORD belongs to, never the user's global role.
 *
 * Resolved from `project_user`, NOT Spatie's model_has_roles, for three reasons:
 *
 *  1. The whole API runs behind `team.global`, which pins the permission
 *     registrar's team id to 0. Spatie's `roles` relation therefore resolves
 *     GLOBAL roles; pulling project roles out of it would mean flipping the
 *     registrar scope per record inside a serialization loop.
 *  2. `project_user` carries is_active / deactivated_at / soft deletes, which
 *     model_has_roles cannot. A revoked assignment must not still print.
 *  3. RoleAssignmentService::assignProjectRole() writes both stores in one
 *     transaction, so project_user is authoritative and cannot drift.
 *
 * That same ambient global scope is then an asset rather than an obstacle: it
 * makes $user->roles exactly the global list the fallback needs.
 */
class ProjectRole
{
    /**
     * Seniority, most senior first. Used to pick ONE label when a user holds
     * several roles on a project — the unique index on project_user is
     * (project_id, user_id, role_id), so that is a supported state, not an edge
     * case.
     *
     * Roles can also be created at runtime (POST /roles), so anything not
     * listed here still prints — see label()'s final fallback. Keep in step
     * with RoleSeeder.
     */
    private const SENIORITY = [
        'Admin',
        'Project Manager',
        'Assistant Project Manager',
        'Project Coordinator',
        'Site Engineer',
        'Foreman',
        'Procurement',
    ];

    /**
     * The user's designation on $projectId: their most senior ACTIVE project
     * role, falling back to their most senior global role, else null.
     *
     * The global fallback is what makes an Admin-authored record read "Admin".
     * assignGlobalRole() deliberately does not write project_user, so an
     * administrator has no project row at all and would otherwise come back
     * null on every project.
     */
    public static function label(?User $user, ?int $projectId): ?string
    {
        if (! $user) {
            return null;
        }

        return self::mostSenior(self::projectRoleNames($user, $projectId))
            ?? self::mostSenior(self::globalRoleNames($user));
    }

    /**
     * Active role names this user holds on the given project.
     *
     * Re-filters by project_id even though callers eager-load already
     * constrained: the relation is unconstrained on the model, so a caller that
     * loads it plainly would otherwise leak another project's roles into this
     * one's response.
     *
     * @return array<int,string>
     */
    private static function projectRoleNames(User $user, ?int $projectId): array
    {
        if ($projectId === null) {
            return [];
        }

        $user->loadMissing(['projectAssignments' => fn ($q) => $q->where('is_active', true)->with('role')]);

        return $user->projectAssignments
            ->filter(fn ($assignment) => (int) $assignment->project_id === (int) $projectId
                && (bool) $assignment->is_active)
            ->map(fn ($assignment) => $assignment->role?->name)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The user's global role names.
     *
     * Reads $user->roles under the ambient permissions scope, which every route
     * fixes to the global sentinel (0) via the `team.global` middleware — so
     * this is the global list without any scope juggling.
     *
     * @return array<int,string>
     */
    private static function globalRoleNames(User $user): array
    {
        $user->loadMissing('roles');

        return $user->roles->pluck('name')->filter()->unique()->values()->all();
    }

    /**
     * The most senior of the given role names, or the first one when none of
     * them appear in SENIORITY — so a role added at runtime still prints
     * instead of silently disappearing.
     *
     * @param  array<int,string>  $names
     */
    private static function mostSenior(array $names): ?string
    {
        if ($names === []) {
            return null;
        }

        foreach (self::SENIORITY as $rank) {
            if (in_array($rank, $names, true)) {
                return $rank;
            }
        }

        return $names[0];
    }
}
