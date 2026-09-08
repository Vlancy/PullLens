<?php

namespace App\Services\Git;

use App\Models\GIT\GitProviderApp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class GitHubAppInstallationCleaner
{
    private const API_BASE = 'https://api.github.com';

    /**
     * Uninstall the GitHub App from every account it is installed on.
     *
     * GitHub exposes no API to delete an app registration, so the most we can do
     * programmatically is revoke all access by removing each installation. The
     * registration itself must be deleted by the operator on GitHub. Failures are
     * reported but swallowed so local cleanup still proceeds if the app was
     * already removed on GitHub.
     */
    public function uninstallAll(GitProviderApp $app): void
    {
        if (blank($app->app_id) || blank($app->private_key)) {
            return;
        }

        try {
            $jwt = $this->jwt($app->app_id, $app->private_key);

            if ($jwt === null) {
                return;
            }

            $listResponse = $this->request($jwt)
                ->get(self::API_BASE.'/app/installations', ['per_page' => 100]);

            // App already deleted from GitHub - nothing to uninstall locally.
            if ($listResponse->status() === 404) {
                return;
            }

            $listResponse->throw();

            foreach ((array) $listResponse->json() as $installation) {
                $installationId = data_get($installation, 'id');

                if ($installationId === null) {
                    continue;
                }

                // 204 = success, 404 = already removed - both are acceptable outcomes.
                $this->request($jwt)
                    ->delete(self::API_BASE."/app/installations/{$installationId}");
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Build an app-authenticated GitHub request using a short-lived JWT.
     */
    private function request(string $jwt): PendingRequest
    {
        return Http::withToken($jwt)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);
    }

    /**
     * Mint a GitHub App JWT (RS256) signed with the stored private key.
     *
     * Returns null when the key cannot sign so the caller skips network calls
     * instead of authenticating with an empty signature.
     */
    private function jwt(string $appId, string $privateKey): ?string
    {
        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            return null;
        }

        $now = time();

        $signingInput = implode('.', [
            $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url((string) json_encode([
                'iat' => $now - 60,
                'exp' => $now + 540,
                'iss' => $appId,
            ])),
        ]);

        $signature = '';

        if (openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256) === false) {
            return null;
        }

        return $signingInput.'.'.$this->base64Url($signature);
    }

    /**
     * Base64url-encode a value without padding, per the JWT spec.
     */
    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
