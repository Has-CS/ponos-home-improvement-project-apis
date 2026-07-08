<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        // Roles are defined ONCE, globally (project_id = null).
        // They are SCOPED to a project at assignment time via model_has_roles.
        $matrix = [
            'Admin' => ['*'], // all permissions
            'Project Manager' => [
                'view_project',
                'edit_project',
                'manage_milestones',
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
            ],
            'Assistant Project Manager' => [
                'view_project',
                'manage_milestones',
                'view_pricing',
                'create_material_request',
                'approve_material_request',
                'create_change_request',
                'submit_daily_log',
                'view_daily_log',
                'view_inventory',
                'view_reports',
            ],
            'Project Coordinator' => [
                'view_project',
                'manage_milestones',
                'create_material_request',
                'submit_daily_log',
                'view_daily_log',
                'view_inventory',
                'view_reports',
            ],
            'Site Engineer' => [
                'view_project',
                'create_material_request',
                'create_change_request',
                'submit_daily_log',
                'view_daily_log',
                'view_inventory',
            ],
            'Foreman' => [
                'view_project',
                'create_material_request',
                'submit_daily_log',
                'view_daily_log',
                'view_inventory',
            ],
            'Procurement' => [
                'view_project',
                'view_pricing',
                'edit_pricing',
                'approve_material_request',
                'view_inventory',
                'update_inventory',
                'view_reports',
            ],
        ];

        $all = \Spatie\Permission\Models\Permission::where('guard_name', 'api')->pluck('name')->all();

        foreach ($matrix as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'api']);
            $grant = $perms === ['*'] ? $all : $perms;
            $role->syncPermissions($grant);
        }
    }
}
