<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 *
 * Requires LookupSeeder to have already run (resolves project_type_id /
 * project_status_id by code — see App\Models\ProjectStatus::ACTIVE).
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code'              => fake()->unique()->bothify('PRJ-####'),
            'name'              => fake()->company() . ' Project',
            'client_id'         => Client::factory(),
            'project_type_id'   => ProjectType::query()->value('id'),
            'project_status_id' => ProjectStatus::where('code', ProjectStatus::ACTIVE)->value('id'),
            'site_address'      => fake()->address(),
            'budget'            => fake()->randomFloat(2, 10000, 500000),
            'start_date'        => fake()->date(),
            'end_date'          => null,
            'created_by'        => null,
        ];
    }
}
