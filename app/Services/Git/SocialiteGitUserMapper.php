<?php

namespace App\Services\Git;

use App\Enums\GIT\GitProvider;
use Carbon\CarbonImmutable;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;

class SocialiteGitUserMapper
{
    /**
     * Normalize a Socialite provider user into PullLens Git account data.
     */
    public function map(GitProvider $provider, SocialiteUserContract $socialiteUser): ConnectedGitAccount
    {
        return new ConnectedGitAccount(
            provider: $provider,
            providerUserId: (string) $socialiteUser->getId(),
            nickname: $socialiteUser->getNickname(),
            name: $socialiteUser->getName(),
            email: $socialiteUser->getEmail(),
            avatarUrl: $socialiteUser->getAvatar(),
            accessToken: (string) data_get($socialiteUser, 'token'),
            refreshToken: data_get($socialiteUser, 'refreshToken'),
            scopes: array_values(data_get($socialiteUser, 'approvedScopes', $provider->scopes()) ?? []),
            tokenExpiresAt: $this->tokenExpiresAt(data_get($socialiteUser, 'expiresIn')),
        );
    }

    /**
     * Convert provider token TTL seconds into an immutable expiry timestamp.
     */
    private function tokenExpiresAt(mixed $expiresIn): ?CarbonImmutable
    {
        if (! is_numeric($expiresIn)) {
            return null;
        }

        return CarbonImmutable::now()->addSeconds((int) $expiresIn);
    }
}
