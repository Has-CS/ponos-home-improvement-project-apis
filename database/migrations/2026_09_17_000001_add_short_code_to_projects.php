<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `projects.short_code` — the project's initials, which lead every purchase-order
 * number: "Surbana Jhons" → SJ → `SJ-2026-09-00001`.
 *
 * Stored rather than derived from the name at print time on purpose. A project
 * may be renamed, and an order already issued to a vendor must keep the number
 * it was issued under.
 *
 * Deliberately NULLABLE at database level even though every row is populated
 * here and every write path fills it: making it NOT NULL would break
 * Project::factory() and every direct insert across the test suite for no gain,
 * since PurchaseOrderService refuses to mint a number without one anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('short_code', Project::SHORT_CODE_MAX)->nullable()->after('code');
        });

        $this->backfill();

        // Partial, matching projects_code_unique exactly: a soft-deleted project
        // must not hold its short code hostage forever.
        DB::statement('CREATE UNIQUE INDEX projects_short_code_unique ON projects (short_code) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS projects_short_code_unique');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('short_code');
        });
    }

    /**
     * Give every existing project a code, so purchase orders keep working the
     * moment this ships.
     *
     * Uses the same Project::deriveShortCode() the application uses, and resolves
     * collisions in PHP against the codes assigned so far in this loop — the
     * model's uniqueShortCode() cannot be used here because it queries a column
     * that is still empty for the rows ahead of it.
     */
    private function backfill(): void
    {
        $taken = [];

        foreach (DB::table('projects')->select('id', 'name')->orderBy('id')->get() as $project) {
            $base = Project::deriveShortCode((string) $project->name);
            $candidate = $base;
            $suffix = 1;

            while (in_array($candidate, $taken, true)) {
                $suffix++;
                $candidate = substr($base, 0, Project::SHORT_CODE_MAX - strlen((string) $suffix)).$suffix;
            }

            $taken[] = $candidate;

            DB::table('projects')->where('id', $project->id)->update(['short_code' => $candidate]);
        }
    }
};
