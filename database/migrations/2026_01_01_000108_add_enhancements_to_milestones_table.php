<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enhances milestones for construction-tracking parity with the client spec:
 * business code, phase/status lookups, single predecessor, hybrid
 * responsible-party, deliverable, payment fields; renames target_date ->
 * planned_date and completed_at -> actual_date (date, not timestamptz).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->renameColumn('target_date', 'planned_date');
            $table->renameColumn('completed_at', 'actual_date');
        });

        // Type change timestamptz -> date (discards time-of-day/tz component).
        // Raw SQL because Laravel's Postgres grammar doesn't emit a USING clause for ->change().
        DB::statement('ALTER TABLE milestones ALTER COLUMN actual_date TYPE date USING actual_date::date');

        Schema::table('milestones', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('project_id');

            $table->foreignId('phase_id')->nullable()->after('code')
                ->constrained('milestone_phases')->restrictOnDelete();

            $table->foreignId('status_id')->nullable()->after('phase_id')
                ->constrained('milestone_statuses')->restrictOnDelete();

            $table->foreignId('predecessor_id')->nullable()->after('sequence')
                ->constrained('milestones')->restrictOnDelete();

            $table->foreignId('responsible_user_id')->nullable()->after('predecessor_id')
                ->constrained('users')->restrictOnDelete();

            $table->string('responsible_party_label', 150)->nullable()->after('responsible_user_id');

            $table->text('deliverable')->nullable()->after('description');

            $table->boolean('is_payment_milestone')->default(false)->after('actual_date');
            $table->decimal('payment_amount', 16, 2)->nullable()->after('is_payment_milestone');

            $table->index('phase_id');
            $table->index('predecessor_id');
            $table->index('responsible_user_id');
        });

        // Backfill status_id for any pre-existing rows using the two rows
        // guaranteed present by the previous migration.
        $notStartedId = DB::table('milestone_statuses')->where('code', 'not_started')->value('id');
        $completedId = DB::table('milestone_statuses')->where('code', 'completed')->value('id');

        DB::table('milestones')->whereNull('actual_date')->update(['status_id' => $notStartedId]);
        DB::table('milestones')->whereNotNull('actual_date')->update(['status_id' => $completedId]);

        DB::statement('ALTER TABLE milestones ALTER COLUMN status_id SET NOT NULL');
        DB::statement('CREATE INDEX milestones_status_id_index ON milestones (status_id)');

        DB::statement('CREATE UNIQUE INDEX milestones_project_id_code_unique ON milestones (project_id, code) WHERE deleted_at IS NULL AND code IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS milestones_project_id_code_unique');
        DB::statement('DROP INDEX IF EXISTS milestones_status_id_index');

        Schema::table('milestones', function (Blueprint $table) {
            $table->dropForeign(['phase_id']);
            $table->dropForeign(['status_id']);
            $table->dropForeign(['predecessor_id']);
            $table->dropForeign(['responsible_user_id']);
            $table->dropColumn([
                'code', 'phase_id', 'status_id', 'predecessor_id',
                'responsible_user_id', 'responsible_party_label',
                'deliverable', 'is_payment_milestone', 'payment_amount',
            ]);
        });

        DB::statement('ALTER TABLE milestones ALTER COLUMN actual_date TYPE timestamptz USING actual_date::timestamptz');

        Schema::table('milestones', function (Blueprint $table) {
            $table->renameColumn('planned_date', 'target_date');
            $table->renameColumn('actual_date', 'completed_at');
        });
    }
};
