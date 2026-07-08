<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable Postgres extensions required by the PONOS schema:
 *  - citext     : case-insensitive emails (user_credentials.email, clients.email, etc.)
 *  - pgcrypto   : gen_random_uuid() default on the notifications PK
 *  - btree_gist : optional, for the EXCLUDE-USING-gist overlap constraint on vendor_rates
 *
 * The DB role running migrations must have rights to CREATE EXTENSION.
 * If extensions are pre-installed by a DBA, the IF NOT EXISTS guard makes this a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
    }

    public function down(): void
    {
        // Intentionally do nothing. Dropping shared extensions on rollback is hostile
        // to other databases in the same cluster.
    }
};
