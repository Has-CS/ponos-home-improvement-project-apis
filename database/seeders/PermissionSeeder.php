<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Global scope for definitions.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $permissions = [
            'view_project',
            'create_project',
            'edit_project',
            'delete_project',
            'manage_milestones',
            'manage_lookups',
            'view_pricing',
            'edit_pricing',
            'create_material_request',
            'approve_material_request',
            'create_change_request',
            'approve_change_request',
            'submit_daily_log',
            'view_daily_log',
            'view_inventory',
            'update_inventory',
            'view_reports',
            'manage_users',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
    }
}
