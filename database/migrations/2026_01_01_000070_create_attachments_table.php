<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * attachments — polymorphic file store reused everywhere: daily-log photos,
 * delivery photos, BOL PDFs, auto-generated change-order documents, captured
 * signatures.
 *
 * project_id is intentionally denormalized (§5) for fast project-scoped media
 * galleries — the polymorphic parent would otherwise require a per-type join.
 *
 * attachment_type uses varchar + CHECK rather than a lookup table: it is a
 * stable internal discriminator, not user-managed workflow vocabulary (§5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();

            // Polymorphic — no DB-level FK on these two columns.
            $table->string('attachable_type', 120);
            $table->unsignedBigInteger('attachable_id');

            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->restrictOnDelete();

            $table->string('attachment_type', 30)->default('document');

            $table->string('disk', 40)->default('s3');
            $table->string('file_path', 1024);
            $table->string('file_name', 255);
            $table->string('mime_type', 120)->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('captured_at')->nullable();

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['attachable_type', 'attachable_id']);
            $table->index('project_id');
            $table->index('attachment_type');
        });

        DB::statement("ALTER TABLE attachments
                         ADD CONSTRAINT attachments_attachment_type_check
                         CHECK (attachment_type IN ('photo','bol','document','signature'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
