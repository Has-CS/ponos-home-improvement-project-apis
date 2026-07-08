<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * email_logs — audit + delivery tracking for all outbound mail (welcome /
 * credentials, password-reset link, PO notice to vendor with tracking ID,
 * CO document to GC).
 *
 * recipient_user_id is nullable because the GC is external and has no users row.
 *
 * status is varchar + CHECK rather than a lookup table — stable internal
 * discriminator, not user-managed workflow vocabulary (§5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('recipient_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('to_email', 255);
            $table->string('subject', 255);
            $table->string('template', 120)->nullable();

            // Polymorphic — what business object this email relates to.
            $table->string('mailable_type', 120)->nullable();
            $table->unsignedBigInteger('mailable_id')->nullable();

            $table->string('status', 20)->default('queued');
            $table->string('provider_message_id', 160)->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('sent_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['mailable_type', 'mailable_id']);
            $table->index('recipient_user_id');
            $table->index('status');
        });

        DB::statement("ALTER TABLE email_logs
                         ALTER COLUMN to_email TYPE CITEXT USING to_email::citext");

        DB::statement("ALTER TABLE email_logs
                         ADD CONSTRAINT email_logs_status_check
                         CHECK (status IN ('queued','sent','failed','bounced'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
