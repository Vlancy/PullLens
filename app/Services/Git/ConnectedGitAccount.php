<?php

namespace App\Services\Git;

use App\Enums\GIT\GitProvider;
use Carbon\CarbonImmutable;

readonly class ConnectedGitAccount
{
    /**
     * Capture normalized provider identity and token data before persistence.
     *
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public GitProvider $provider,
        public string $providerUserId,
        public ?string $nickname,
        public ?string $name,
        public ?string $email,
        public ?string $avatarUrl,
        public string $accessToken,
        public ?string $refreshToken,
        public array $scopes,
        public ?CarbonImmutable $tokenExpiresAt,
    ) {}
}
