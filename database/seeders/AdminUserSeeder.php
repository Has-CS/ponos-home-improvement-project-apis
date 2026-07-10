<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserCredential;
use App\Models\UserStatus;
use App\Services\Rbac\RoleAssignmentService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Creates a default admin account and assigns it the global Admin role
 * through the app's own scoped services (never a raw assignRole() call),
 * so a working admin always exists after migrate:fresh --seed.
 *
 * Deliberately does NOT go through UserService::create() — that method
 * always generates a random password and emails it (R-2, correct for the
 * "admin invites a teammate" flow), which is wrong here: this bootstrap
 * record has no real inbox behind it, so the password must be set directly
 * from ADMIN_EMAIL/ADMIN_PASSWORD instead.
 *
 * IMPORTANT: production deployments must set real ADMIN_EMAIL/ADMIN_PASSWORD
 * env vars — the admin@ponos.test / Password123! defaults are dev-only
 * placeholders and must never be relied on outside local/test environments.
 */
class AdminUserSeeder extends Seeder
{
    public function run(RoleAssignmentService $rbac): void
    {
        $email = env('ADMIN_EMAIL', 'admin@ponos.test');
        $password = env('ADMIN_PASSWORD', 'Password123!');

        $credential = UserCredential::where('email', $email)->first();

        if ($credential) {
            $admin = $credential->user;
        } else {
            $statusId = UserStatus::where('code', UserStatus::ACTIVE)->value('id');

            $admin = User::create([
                'first_name'     => 'Ada',
                'last_name'      => 'Admin',
                'user_status_id' => $statusId,
            ]);

            $admin->credential()->create([
                'email'                => $email,
                'password'             => $password, // hashed by cast — known value, not random
                'must_change_password' => true,
            ]);
        }

        $role = Role::where('name', 'Admin')->where('guard_name', 'api')->whereNull('project_id')->firstOrFail();
        $rbac->assignGlobalRole($admin, $role);
    }
}
