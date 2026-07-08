<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * material_requests — field-friendly request header.
 *
 * R-3: NO cost_code_id on the header. Cost code is captured PER LINE
 * (material_request_items.cost_code_id), so a single request can mix
 * materials charged to different codes — concrete to Concrete, plywood to
 * Rough Carpentry — without the "split the request" friction.
 *
 * request_no is minted by document_sequences (R-5), not application
 * MAX()+1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 40);

            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('material_request_status_id')
                ->constrained('material_request_statuses')
                ->restrictOnDelete();
            $table->foreignId('urgency_id')->constrained('urgencies')->restrictOnDelete();

            $table->date('needed_by_date')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('requested_by');
        });

        DB::statement("CREATE UNIQUE INDEX material_requests_request_no_unique
                         ON material_requests (request_no)
                         WHERE deleted_at IS NULL");

        // Hot read path: project board filtered by status, live rows only.
        DB::statement("CREATE INDEX material_requests_project_status_live_idx
                         ON material_requests (project_id, material_request_status_id)
                         WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('material_requests');
    }
};
