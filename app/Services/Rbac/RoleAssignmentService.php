<?php

namespace App\Services\Rbac;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAssignmentService
{
    private function registrar(): PermissionRegistrar
    {
        return app(PermissionRegistrar::class);
    }

    /**
     * Run a callback under a specific team (project) scope, restoring the
     * previous scope afterward and clearing the model's relation cache.
     */
    private function underScope(?int $projectId, User $user, callable $cb): mixed
    {
        $previous = $this->registrar()->getPermissionsTeamId();
        $this->registrar()->setPermissionsTeamId($projectId);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $cb();
        } finally {
            $this->registrar()->setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /* ---------------- GLOBAL role assignment ---------------- */

    /**
     * Assign a GLOBAL role. Used for Admin / management.
     * Does NOT touch project_user (that table is project-scoped by definition).
     * Uses team id 0 (not null) — the NOT-NULL sentinel for the pivot tables;
     * the role definition itself still has project_id = NULL (see RoleSeeder).
     */
    public function assignGlobalRole(User $user, Role $role): User
    {
        return $this->underScope(0, $user, function () use ($user, $role) {
            $user->assignRole($role);
            return $user->load('roles');
        });
    }

    public function revokeGlobalRole(User $user, Role $role): User
    {
        return $this->underScope(0, $user, function () use ($user, $role) {
            $user->removeRole($role);
            return $user->load('roles');
        });
    }

    /**
     * The user's current global/default roles (team id 0).
     */
    public function globalRoles(User $user): \Illuminate\Support\Collection
    {
        return $this->underScope(0, $user, fn() => $user->roles()->get());
    }

    /* ---------------- PROJECT-scoped assignment ---------------- */

    /**
     * Assign a user a role WITHIN a project. Writes BOTH:
     *   - project_user (application assignment + lifecycle metadata)
     *   - model_has_roles via Spatie under the project team scope (authorization)
     * Transactionally, so the two never diverge.
     */
    public function assignProjectRole(
        Project $project,
        User $user,
        Role $role,
        ?int $assignedBy,
    ): ProjectUser {
        return DB::transaction(function () use ($project, $user, $role, $assignedBy) {
            // A project has exactly one active Project Manager at a time.
            if ($role->name === 'Project Manager') {
                $existing = ProjectUser::where('project_id', $project->id)
                    ->where('role_id', $role->id)
                    ->where('is_active', true)
                    ->where('user_id', '!=', $user->id)
                    ->with('user')
                    ->first();

                if ($existing) {
                    throw new \RuntimeException(
                        "Project already has a Project Manager: {$existing->user->first_name} {$existing->user->last_name}. Remove them first."
                    );
                }
            }

            // 1) Application assignment record (idempotent on the unique key).
            $projectUser = ProjectUser::withTrashed()->firstOrNew([
                'project_id' => $project->id,
                'user_id'    => $user->id,
                'role_id'    => $role->id,
            ]);

            $projectUser->fill([
                'is_active'      => true,
                'assigned_by'    => $assignedBy,
                'assigned_at'    => now(),
                'deactivated_at' => null,
            ]);
            if ($projectUser->trashed()) {
                $projectUser->restore();
            }
            $projectUser->save();

            // 2) Authorization record via Spatie, scoped to this project.
            $this->underScope($project->id, $user, function () use ($user, $role) {
                if (! $user->hasRole($role->name)) {
                    $user->assignRole($role->name);
                }
            });

            return $projectUser->load(['role', 'user', 'project']);
        });
    }

    /**
     * Remove a specific role from a user within a project (both stores).
     */
    public function revokeProjectRole(Project $project, User $user, Role $role): void
    {
        DB::transaction(function () use ($project, $user, $role) {
            ProjectUser::where('project_id', $project->id)
                ->where('user_id', $user->id)
                ->where('role_id', $role->id)
                ->update(['is_active' => false, 'deactivated_at' => now(), 'deleted_at' => now()]);

            $this->underScope($project->id, $user, function () use ($user, $role) {
                if ($user->hasRole($role->name)) {
                    $user->removeRole($role->name);
                }
            });
        });
    }

    /**
     * Remove a user from a project entirely: deactivate all their assignments
     * and strip all their roles in that project scope.
     */
    public function removeUserFromProject(Project $project, User $user): void
    {
        DB::transaction(function () use ($project, $user) {
            ProjectUser::where('project_id', $project->id)
                ->where('user_id', $user->id)
                ->update(['is_active' => false, 'deactivated_at' => now(), 'deleted_at' => now()]);

            $this->underScope($project->id, $user, function () use ($user) {
                foreach ($user->getRoleNames() as $roleName) {
                    $user->removeRole($roleName);
                }
            });
        });
    }

    /**
     * Effective roles + permissions for a user, both global and per-project.
     */
    public function effectiveAccess(User $user): array
    {
        // Global
        $global = $this->underScope(0, $user, fn() => [
            'roles'       => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ]);

        // Per project the user has any assignment in
        $projectIds = ProjectUser::where('user_id', $user->id)
            ->where('is_active', true)
            ->distinct()->pluck('project_id');

        $perProject = [];
        foreach ($projectIds as $pid) {
            $perProject[] = $this->underScope((int) $pid, $user, fn() => [
                'project_id'  => (int) $pid,
                'roles'       => $user->getRoleNames()->values(),
                'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            ]);
        }

        return ['global' => $global, 'projects' => $perProject];
    }
}
