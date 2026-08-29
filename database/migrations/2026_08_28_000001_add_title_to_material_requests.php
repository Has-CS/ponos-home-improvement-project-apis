<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A human-readable name for a material request.
 *
 * Until now the only identifiers were `request_no` ("MR-0007") and, on a
 * free-text request, the body of `request_text` — so the project list, the
 * cross-project buyer queue and the printed document all showed a number with
 * nothing saying what was actually being asked for.
 *
 * Deliberately NULLABLE, and deliberately NOT `notes` or `request_text`:
 *
 *  - nullable, because material requests already exist without one and none of
 *    them can be given an honest title retroactively. Requiring it would mean
 *    synthesising values for historical rows and breaking every caller that
 *    already posts without it. The frontend enforces it on the form instead;
 *    making the column mandatory later is a backfill plus a rule change.
 *  - not `notes`, which is editable commentary about the request rather than a
 *    name for it, and not `request_text`, which is the requester's original
 *    message and freezes at submit. The title is a label anyone may correct at
 *    any point in the editable window, so it follows `notes`, not
 *    `request_text`.
 *
 * 200 chars matches `change_orders.title`, the existing precedent for a
 * document title in this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->string('title', 200)->nullable()->after('request_no');
        });
    }

    public function down(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
