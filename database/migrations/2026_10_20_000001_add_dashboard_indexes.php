<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the dashboard's aggregate queries (GET /api/v1/dashboard).
 *
 * Additive only — no column or constraint changes. Partial on live rows, the
 * same idiom as material_requests_project_status_live_idx.
 */
return new class extends Migration
{
    private const INDEXES = [
        // Upcoming / overdue: open milestones by project and date.
        'milestones_open_planned_idx' => 'ON milestones (project_id, planned_date) WHERE deleted_at IS NULL AND actual_date IS NULL',
        // "Your milestones due".
        'milestones_responsible_planned_idx' => 'ON milestones (responsible_user_id, planned_date) WHERE deleted_at IS NULL AND responsible_user_id IS NOT NULL',
        // Status counts within the caller's projects.
        'purchase_orders_project_status_live_idx' => 'ON purchase_orders (project_id, purchase_order_status_id) WHERE deleted_at IS NULL',
        // Overdue / expected deliveries.
        'purchase_orders_expected_delivery_idx' => 'ON purchase_orders (expected_delivery_date) WHERE deleted_at IS NULL AND expected_delivery_date IS NOT NULL',
        // GC wait aging.
        'change_orders_sent_to_gc_idx' => 'ON change_orders (sent_to_gc_at) WHERE deleted_at IS NULL AND sent_to_gc_at IS NOT NULL',
        // "Have I filed today?" per user.
        'daily_logs_logged_by_date_idx' => 'ON daily_logs (logged_by, log_date) WHERE deleted_at IS NULL',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
