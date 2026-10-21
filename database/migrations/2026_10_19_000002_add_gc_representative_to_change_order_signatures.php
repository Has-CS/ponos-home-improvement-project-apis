<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which stored representative a signature was captured for, when one was picked.
 *
 * A LINK only. The signer_* columns stay the record of what was signed — they are
 * copied from the representative at capture — so this FK is for traceability and
 * never for display. Null on every signature typed in by hand, including all that
 * predate this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_order_signatures', function (Blueprint $table) {
            $table->foreignId('gc_representative_id')
                ->nullable()
                ->after('change_order_id')
                ->constrained('gc_representatives')
                ->restrictOnDelete();

            $table->index('gc_representative_id');
        });
    }

    public function down(): void
    {
        Schema::table('change_order_signatures', function (Blueprint $table) {
            $table->dropForeign(['gc_representative_id']);
            $table->dropIndex(['gc_representative_id']);
            $table->dropColumn('gc_representative_id');
        });
    }
};
