<?php

namespace Database\Factories\GIT;

use App\Enums\GIT\GitProvider;
use App\Models\GIT\GitAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GitAccount>
 */
class GitAccountFactory extends Factory
{
    protected $model = GitAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => GitProvider::Github,
            'provider_user_id' => (string) fake()->unique()->numberBetween(1_000, 999_999),
            'nickname' => fake()->unique()->userName(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'avatar_url' => fake()->imageUrl(),
            'access_token' => 'gho_'.fake()->lexify(str_repeat('?', 36)),
            'refresh_token' => null,
            'scopes' => 'repo,read:org',
            'token_expires_at' => null,
            'connected_at' => now(),
            'last_used_at' => null,
        ];
    }
}
