<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 *
 * Requires LookupSeeder to have already run (resolves user_status_id by
 * code — see App\Models\UserStatus::ACTIVE).
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name'     => fake()->firstName(),
            'last_name'      => fake()->lastName(),
            'gender_id'      => null,
            'date_of_birth'  => fake()->date(),
            'user_status_id' => UserStatus::where('code', UserStatus::ACTIVE)->value('id'),
            'created_by'     => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            $credential = $user->credential()->create([
                'email'                => fake()->unique()->safeEmail(),
                'password'             => static::$password ??= 'password',
                'must_change_password' => false,
                'password_changed_at'  => now(),
            ]);
            $user->setRelation('credential', $credential);
        });
    }

    /**
     * Attach (or overwrite) a known email on the created user's credential.
     */
    public function withEmail(string $email): static
    {
        return $this->afterCreating(function (User $user) use ($email) {
            $credential = $user->credential()->updateOrCreate(
                ['user_id' => $user->id],
                ['email' => $email],
            );
            $user->setRelation('credential', $credential);
        });
    }
}
