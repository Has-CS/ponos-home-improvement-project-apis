<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Close the estimates ↔ estimate_versions circular reference.
 *
 * estimates was created in 000083 without current_version_id because the
 * referenced table didn't yet exist. Now that estimate_versions is in place
 * (000084), we can safely add the nullable FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->foreignId('current_version_id')
                ->nullable()
                ->after('name')
                ->constrained('estimate_versions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
            $table->dropColumn('current_version_id');
        });
    }
};
