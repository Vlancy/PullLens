<?php

namespace Database\Factories\Users;

use App\Enums\Users\UserRole;
use App\Models\Users\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Grant the user a role, provisioning the role/permission matrix if the test
     * database does not have it yet.
     *
     * Keeps role setup out of individual tests: `User::factory()->withRole(...)`
     * yields a user who can actually reach the routes under test.
     */
    public function withRole(UserRole $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            if (! Role::query()->where('name', $role->value)->exists()) {
                (new RolesAndPermissionsSeeder)->run();
            }

            $user->syncRoles([$role->value]);
        });
    }

    /**
     * A user with the administrator role - full access to every feature.
     */
    public function admin(): static
    {
        return $this->withRole(UserRole::Admin);
    }

    /**
     * A user with the manager role - repositories and findings, but not users or credentials.
     */
    public function manager(): static
    {
        return $this->withRole(UserRole::Manager);
    }

    /**
     * A user with the member role - read-only access to reports and findings.
     */
    public function member(): static
    {
        return $this->withRole(UserRole::Member);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
