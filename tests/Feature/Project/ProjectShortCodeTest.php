<?php

namespace Tests\Feature\Project;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `projects.short_code` — the initials that lead every purchase-order number
 * for the project ("Surbana Jhons" → SJ → SJ-2026-09-00001).
 *
 * Stored rather than derived at print time: a project may be renamed, and an
 * order already issued must keep the number it was issued under.
 */
class ProjectShortCodeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole(
            $this->admin,
            Role::where('name', 'Admin')->where('guard_name', 'api')->whereNull('project_id')->firstOrFail(),
        );
    }

    /** @param array<string,mixed> $payload */
    private function createProject(array $payload): TestResponse
    {
        return $this->actingAs($this->admin, 'api')->postJson('/api/v1/projects', [
            'code' => 'PRJ-'.fake()->unique()->numerify('####'),
            'name' => 'Surbana Jhons',
            'client_id' => Client::factory()->create()->id,
            'project_type_id' => ProjectType::query()->value('id'),
            ...$payload,
        ]);
    }

    /* ---------------- derivation ---------------- */

    #[DataProvider('names')]
    public function test_initials_are_derived_from_the_name(string $name, string $expected): void
    {
        $this->assertSame($expected, Project::deriveShortCode($name));
    }

    public static function names(): array
    {
        return [
            'two words' => ['Surbana Jhons', 'SJ'],
            'two words again' => ['Silcone Village', 'SV'],
            'digits are ignored' => ['Sky 47 Data Center', 'SDC'],
            'one word takes three letters' => ['Metro', 'MET'],
            'lowercase is raised' => ['surbana jhons', 'SJ'],
            'punctuation is ignored' => ['Smith & Co. Renovation', 'SCR'],
            'capped at six' => ['A B C D E F G H', 'ABCDEF'],
            'nothing usable falls back' => ['123 456', 'PRJ'],
        ];
    }

    public function test_a_project_created_without_a_short_code_gets_the_derived_one(): void
    {
        $this->createProject(['name' => 'Surbana Jhons'])
            ->assertStatus(201)
            ->assertJsonPath('data.short_code', 'SJ');
    }

    public function test_a_colliding_name_gets_a_numeric_suffix(): void
    {
        $this->createProject(['name' => 'Surbana Jhons'])->assertStatus(201)
            ->assertJsonPath('data.short_code', 'SJ');

        $this->createProject(['name' => 'Surbana Jhons'])->assertStatus(201)
            ->assertJsonPath('data.short_code', 'SJ2');

        $this->createProject(['name' => 'Surbana Jhons'])->assertStatus(201)
            ->assertJsonPath('data.short_code', 'SJ3');
    }

    /* ---------------- explicit values ---------------- */

    public function test_an_explicit_short_code_is_kept_as_given(): void
    {
        $this->createProject(['name' => 'Surbana Jhons', 'short_code' => 'SURB'])
            ->assertStatus(201)
            ->assertJsonPath('data.short_code', 'SURB');
    }

    public function test_a_duplicate_short_code_is_rejected(): void
    {
        $this->createProject(['short_code' => 'SJ'])->assertStatus(201);

        $this->createProject(['short_code' => 'SJ'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['short_code']);
    }

    public function test_lowercase_and_over_long_short_codes_are_rejected(): void
    {
        $this->createProject(['short_code' => 'sj'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['short_code']);

        $this->createProject(['short_code' => 'TOOLONG'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['short_code']);

        $this->createProject(['short_code' => 'S-J'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['short_code']);
    }

    /* ---------------- editing ---------------- */

    public function test_a_short_code_can_be_changed(): void
    {
        $id = $this->createProject(['short_code' => 'SJ'])->assertStatus(201)->json('data.id');

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/projects/{$id}", ['short_code' => 'SURB'])
            ->assertOk()
            ->assertJsonPath('data.short_code', 'SURB');
    }

    public function test_it_is_exposed_on_the_project_list(): void
    {
        $this->createProject(['short_code' => 'SJ'])->assertStatus(201);

        $this->actingAs($this->admin, 'api')->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('data.items.0.short_code', 'SJ');
    }
}
