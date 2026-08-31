<?php

namespace Database\Factories\GIT;

use App\Enums\GIT\GitProvider;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GitRepository>
 */
class GitRepositoryFactory extends Factory
{
    protected $model = GitRepository::class;

    /**
     * The model's default attributes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $owner = fake()->unique()->userName();
        $name = fake()->unique()->slug(2);

        return [
            'git_account_id' => GitAccount::factory(),
            'provider' => GitProvider::Github,
            'provider_repo_id' => fake()->unique()->numberBetween(1_000, 999_999),
            'installation_id' => fake()->numberBetween(1_000, 999_999),
            'owner_login' => $owner,
            'owner_type' => 'User',
            'name' => $name,
            'full_name' => "{$owner}/{$name}",
            'default_branch' => 'main',
            'is_private' => false,
            'web_url' => "https://github.com/{$owner}/{$name}",
            'reviews_enabled' => true,
            // An empty tracked-branch list means "every branch", which is what most
            // tests want; override it when exercising the branch filter.
            'base_branches' => [],
            'tracked_branches' => [],
        ];
    }

    /**
     * A repository with AI reviews switched off.
     */
    public function reviewsDisabled(): static
    {
        return $this->state(fn (): array => ['reviews_enabled' => false]);
    }
}
