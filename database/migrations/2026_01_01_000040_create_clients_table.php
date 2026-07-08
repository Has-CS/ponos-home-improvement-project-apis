<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * clients — the owners/clients commissioning projects. NOT system users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('contact_name', 160)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('name');
        });

        DB::statement("ALTER TABLE clients
                         ALTER COLUMN email TYPE CITEXT USING email::citext");
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
