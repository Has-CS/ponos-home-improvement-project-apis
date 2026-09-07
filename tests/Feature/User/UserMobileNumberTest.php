<?php

namespace Tests\Feature\User;

use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A user carries a contact mobile number: mandatory when an administrator
 * creates the account, editable afterwards, and present everywhere a user is
 * serialized.
 *
 * The column is nullable (users predate the field) while StoreUserRequest makes
 * it required, so the two halves of that decision are both covered here — a new
 * create must supply one, an existing row with none still reads back cleanly.
 */
class UserMobileNumberTest extends TestCase
{
    use RefreshDatabase;

    private RoleAssignmentService $rbac;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->rbac = app(RoleAssignmentService::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $this->rbac->assignGlobalRole($user, $this->role('Admin'));

        return $user;
    }

    private function role(string $name): Role
    {
        return Role::where('name', $name)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail();
    }

    /**
     * A valid create payload. `email:rfc,dns` is already on this endpoint, so
     * the domain has to be one that actually resolves.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'first_name' => 'Dana',
            'last_name' => 'Whitfield',
            'email' => 'dana.whitfield.'.uniqid().'@gmail.com',
            'mobile_number' => '(203) 491-4431',
            ...$overrides,
        ];
    }

    /* ---------------- create ---------------- */

    public function test_a_user_is_created_with_a_mobile_number_and_it_is_returned(): void
    {
        $response = $this->actingAs($this->admin(), 'api')
            ->postJson('/api/v1/users', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('data.mobile_number', '(203) 491-4431');

        $this->assertDatabaseHas('users', [
            'id' => $response->json('data.id'),
            'mobile_number' => '(203) 491-4431',
        ]);
    }

    public function test_creating_without_a_mobile_number_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['mobile_number']);

        $this->actingAs($this->admin(), 'api')
            ->postJson('/api/v1/users', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mobile_number']);
    }

    /**
     * The pattern has to admit the local US formatting the company writes its
     * own number in, alongside international forms.
     */
    public function test_it_accepts_the_formats_people_actually_type(): void
    {
        foreach (['(203) 491-4431', '+1 203 491 4431', '203-491-4431', '07700900123', '+442079460958'] as $number) {
            $this->actingAs($this->admin(), 'api')
                ->postJson('/api/v1/users', $this->payload(['mobile_number' => $number]))
                ->assertStatus(201)
                ->assertJsonPath('data.mobile_number', $number);
        }
    }

    public function test_it_rejects_values_that_are_not_phone_numbers(): void
    {
        foreach (['not-a-phone', '12345', 'call me', '+1 (203) 491-4431 ext. 22', ''] as $number) {
            $this->actingAs($this->admin(), 'api')
                ->postJson('/api/v1/users', $this->payload(['mobile_number' => $number]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['mobile_number']);
        }
    }

    public function test_two_users_may_share_a_mobile_number(): void
    {
        // Shared site phones are ordinary in construction — this is the
        // deliberate absence of a unique constraint, not an oversight.
        $admin = $this->admin();
        $number = '(203) 491-4431';

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/users', $this->payload(['mobile_number' => $number]))
            ->assertStatus(201);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/users', $this->payload(['mobile_number' => $number]))
            ->assertStatus(201)
            ->assertJsonPath('data.mobile_number', $number);
    }

    /* ---------------- update ---------------- */

    public function test_the_mobile_number_can_be_updated(): void
    {
        $target = User::factory()->create(['mobile_number' => '(203) 491-4431']);

        $this->actingAs($this->admin(), 'api')
            ->patchJson("/api/v1/users/{$target->id}", ['mobile_number' => '+1 914 555 0142'])
            ->assertStatus(200)
            ->assertJsonPath('data.mobile_number', '+1 914 555 0142');

        $this->assertSame('+1 914 555 0142', $target->fresh()->mobile_number);
    }

    public function test_an_invalid_mobile_number_is_rejected_on_update(): void
    {
        $target = User::factory()->create(['mobile_number' => '(203) 491-4431']);

        $this->actingAs($this->admin(), 'api')
            ->patchJson("/api/v1/users/{$target->id}", ['mobile_number' => 'not-a-phone'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mobile_number']);

        $this->assertSame('(203) 491-4431', $target->fresh()->mobile_number);
    }

    public function test_omitting_the_key_leaves_the_stored_number_alone(): void
    {
        $target = User::factory()->create(['mobile_number' => '(203) 491-4431']);

        $this->actingAs($this->admin(), 'api')
            ->patchJson("/api/v1/users/{$target->id}", ['first_name' => 'Renamed'])
            ->assertStatus(200)
            ->assertJsonPath('data.mobile_number', '(203) 491-4431');

        $this->assertSame('Renamed', $target->fresh()->first_name);
        $this->assertSame('(203) 491-4431', $target->fresh()->mobile_number);
    }

    public function test_the_number_cannot_be_cleared_by_sending_null(): void
    {
        // `sometimes`,`required` — sending the key means committing to a value.
        $target = User::factory()->create(['mobile_number' => '(203) 491-4431']);

        $this->actingAs($this->admin(), 'api')
            ->patchJson("/api/v1/users/{$target->id}", ['mobile_number' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mobile_number']);

        $this->assertSame('(203) 491-4431', $target->fresh()->mobile_number);
    }

    /* ---------------- reads ---------------- */

    public function test_it_appears_in_the_user_list(): void
    {
        User::factory()->create(['mobile_number' => '(203) 491-4431']);

        $response = $this->actingAs($this->admin(), 'api')->getJson('/api/v1/users');

        $response->assertStatus(200);
        $this->assertContains(
            '(203) 491-4431',
            array_column($response->json('data.items'), 'mobile_number'),
        );
    }

    public function test_it_appears_on_the_single_user_endpoint(): void
    {
        $target = User::factory()->create(['mobile_number' => '(203) 491-4431']);

        $this->actingAs($this->admin(), 'api')
            ->getJson("/api/v1/users/{$target->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.mobile_number', '(203) 491-4431');
    }

    public function test_it_appears_on_the_logged_in_user_endpoint(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['mobile_number' => '(203) 491-4431'])->save();

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.mobile_number', '(203) 491-4431');
    }

    public function test_a_user_predating_the_column_still_serializes(): void
    {
        // The nullable column is what keeps existing accounts working; they
        // read back as null rather than 500ing or vanishing from the list.
        $legacy = User::factory()->create(['mobile_number' => null]);

        $this->actingAs($this->admin(), 'api')
            ->getJson("/api/v1/users/{$legacy->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.mobile_number', null);
    }

    /* ---------------- no collateral damage ---------------- */

    public function test_the_other_create_fields_still_work(): void
    {
        $response = $this->actingAs($this->admin(), 'api')
            ->postJson('/api/v1/users', $this->payload([
                'first_name' => 'Pat',
                'last_name' => 'Morgan',
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.first_name', 'Pat')
            ->assertJsonPath('data.last_name', 'Morgan')
            ->assertJsonPath('data.mobile_number', '(203) 491-4431');

        $this->assertNotNull($response->json('data.email'));
    }
}
