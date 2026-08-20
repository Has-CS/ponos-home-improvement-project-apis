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
            'manage_clients',
            'view_pricing',
            'edit_pricing',
            'create_material_request',
            'approve_material_request',
            // Lets the holder END a request at the PM step instead of routing it
            // to Admin. Deliberately separate from approve_material_request so
            // the bypass is explicit and can be revoked without also removing
            // ordinary approval rights. Granted per user, not via a role —
            // Admin picks it up through the '*' wildcard in RoleSeeder.
            'finalize_material_request',
            'manage_purchase_orders',
            'manage_rfqs',
            'receive_deliveries',
            'manage_issues',
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
