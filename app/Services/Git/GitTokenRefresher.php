<?php

namespace App\Services\Git;

use App\Models\GIT\GitAccount;
use App\Repositories\Contracts\GIT\GitAccountRepositoryInterface;
use App\Repositories\Contracts\GIT\GitProviderAppRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitTokenRefresher
{
    private const TOKEN_URL = 'https://github.com/login/oauth/access_token';

    /**
     * Inject the git provider app repository interface and git account repository interface this class delegates to.
     */
    public function __construct(
        private readonly GitProviderAppRepositoryInterface $providerApps,
        private readonly GitAccountRepositoryInterface $gitAccounts,
    ) {}

    /**
     * Return the account with a fresh access token if the current one is expired.
     */
    public function refreshIfExpired(GitAccount $account): GitAccount
    {
        if (! $this->isExpired($account)) {
            return $account;
        }

        return $this->refresh($account);
    }

    /**
     * Whether the stored access token has passed its expiry.
     */
    private function isExpired(GitAccount $account): bool
    {
        return $account->token_expires_at !== null
            && $account->token_expires_at->isPast();
    }

    /**
     * Exchange the refresh token for a new access token and persist it.
     *
     * @throws RuntimeException When the provider app has no OAuth credentials.
     */
    private function refresh(GitAccount $account): GitAccount
    {
        $app = $this->providerApps->findByProvider($account->provider);

        if (! $app || ! $app->client_id || ! $app->client_secret) {
            throw new RuntimeException("No OAuth app credentials for provider [{$account->provider->value}].");
        }

        if (! $account->refresh_token) {
            throw new RuntimeException("GitAccount [{$account->id}] has no refresh token.");
        }

        $response = Http::asJson()
            ->accept('application/json')
            ->post(self::TOKEN_URL, [
                'client_id' => $app->client_id,
                'client_secret' => $app->client_secret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ])
            ->throw()
            ->json();

        $newAccessToken = data_get($response, 'access_token');

        if (! $newAccessToken) {
            throw new RuntimeException('GitHub token refresh returned no access_token: '.json_encode($response));
        }

        $expiresIn = data_get($response, 'expires_in');
        $tokenExpiresAt = is_numeric($expiresIn)
            ? CarbonImmutable::now()->addSeconds((int) $expiresIn)
            : null;

        return DB::transaction(function () use ($account, $response, $newAccessToken, $tokenExpiresAt): GitAccount {
            $locked = $this->gitAccounts->findForUpdate($account->provider, $account->provider_user_id);

            if (! $locked) {
                throw new RuntimeException("GitAccount [{$account->id}] not found during refresh lock.");
            }

            /** @var GitAccount */
            return $this->gitAccounts->update($locked, [
                'access_token' => $newAccessToken,
                'refresh_token' => data_get($response, 'refresh_token', $account->refresh_token),
                'token_expires_at' => $tokenExpiresAt,
                'last_used_at' => now(),
            ]);
        });
    }
}
