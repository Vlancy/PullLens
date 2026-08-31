<?php

namespace App\Services\Git;

use App\Models\GIT\GitAccount;
use App\Repositories\Contracts\GIT\GitAccountRepositoryInterface;
use Illuminate\Database\DatabaseManager;

class GitAccountConnector
{
    /**
     * Create the connector with transaction and persistence dependencies.
     */
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly GitAccountRepositoryInterface $gitAccounts,
    ) {}

    /**
     * Connect or refresh a Git provider identity for this PullLens instance.
     */
    public function connect(ConnectedGitAccount $connectedAccount): GitAccount
    {
        return $this->database->transaction(function () use ($connectedAccount) {
            // Lock the provider account so concurrent callbacks cannot create duplicate system accounts.
            $gitAccount = $this->gitAccounts->findForUpdate(
                $connectedAccount->provider,
                $connectedAccount->providerUserId,
            );

            $attributes = [
                'provider' => $connectedAccount->provider,
                'provider_user_id' => $connectedAccount->providerUserId,
                'nickname' => $connectedAccount->nickname,
                'name' => $connectedAccount->name,
                'email' => $connectedAccount->email,
                'avatar_url' => $connectedAccount->avatarUrl,
                'access_token' => $connectedAccount->accessToken,
                'refresh_token' => $connectedAccount->refreshToken,
                'scopes' => $connectedAccount->scopes,
                'token_expires_at' => $connectedAccount->tokenExpiresAt,
                'connected_at' => $gitAccount?->connected_at ?? now(),
                'last_used_at' => now(),
            ];

            if ($gitAccount) {
                return $this->gitAccounts->update($gitAccount, $attributes);
            }

            return $this->gitAccounts->create($attributes);
        });
    }
}
