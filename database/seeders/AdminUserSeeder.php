<?php

namespace Database\Seeders;

use App\Models\UserCredential;
use App\Services\Rbac\RoleAssignmentService;
use App\Services\User\UserService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Creates a default admin account and assigns it the global Admin role
 * through the app's own scoped services (never a raw assignRole() call),
 * so a working admin always exists after migrate:fresh --seed.
 */
class AdminUserSeeder extends Seeder
{
    public function run(UserService $users, RoleAssignmentService $rbac): void
    {
        $email = env('ADMIN_EMAIL', 'admin@ponos.test');
        $password = env('ADMIN_PASSWORD', 'Password123!');

        $credential = UserCredential::where('email', $email)->first();

        $admin = $credential
            ? $credential->user
            : $users->create([
                'first_name' => 'Ada',
                'last_name'  => 'Admin',
                'email'      => $email,
                'password'   => $password,
            ]);

        $role = Role::where('name', 'Admin')->where('guard_name', 'api')->whereNull('project_id')->firstOrFail();
        $rbac->assignGlobalRole($admin, $role);
    }
}
