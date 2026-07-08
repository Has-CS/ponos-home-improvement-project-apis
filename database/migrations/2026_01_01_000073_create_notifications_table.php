<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * notifications — in-app alerts for internal users (field-to-office issue
 * alerts, PO/CO stakeholder notifications). Mirrors Laravel's stock
 * notifications shape with an explicit project_id FK.
 *
 * uuid PK is the Laravel convention; the DB-side default uses gen_random_uuid()
 * from pgcrypto (enabled in 0001).
 *
 * deleted_at retained only for schema uniformity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 160);
            $table->string('notifiable_type', 120);
            $table->unsignedBigInteger('notifiable_id');

            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->restrictOnDelete();

            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['notifiable_type', 'notifiable_id']);
            $table->index('project_id');
            $table->index('read_at');
        });

        // DB-side UUID default. Application code is then free to omit `id` on insert.
        DB::statement("ALTER TABLE notifications
                         ALTER COLUMN id SET DEFAULT gen_random_uuid()");
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
