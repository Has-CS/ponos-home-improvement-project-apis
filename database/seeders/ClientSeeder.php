<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

/**
 * Standalone dev/demo seeder — sample companies, NOT wired into
 * DatabaseSeeder (fake company data has no place in a real seeded
 * environment). Run manually: php artisan db:seed --class=ClientSeeder
 */
class ClientSeeder extends Seeder
{
    public function run(): void
    {
        Client::factory()->count(5)->create();
    }
}
