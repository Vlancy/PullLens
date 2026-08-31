<?php

namespace App\Services\Git;

class GitHubWebhookVerifier
{
    /**
     * Verify the GitHub HMAC-SHA256 webhook signature against the raw request body.
     *
     * GitHub sends the signature in the header as "sha256=<hex>".
     * We use hash_equals to prevent timing attacks.
     */
    public function verify(string $rawBody, ?string $signature, string $secret): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }
}
