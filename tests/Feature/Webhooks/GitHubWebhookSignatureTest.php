<?php

use App\Enums\GIT\GitProvider;
use App\Models\GIT\GitProviderApp;

/*
| The webhook route is unauthenticated and CSRF-exempt, so the HMAC signature is the
| only thing preventing an anonymous caller from queueing AI review jobs. These tests
| pin the fail-closed behaviour.
*/

function githubApp(string $secret = 'top-secret'): GitProviderApp
{
    return GitProviderApp::query()->create([
        'provider' => GitProvider::Github,
        'name' => 'PullLens',
        'app_id' => '12345',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'webhook_secret' => $secret,
        'private_key' => 'private-key',
        'slug' => 'pulllens',
        'configured_at' => now(),
    ]);
}

function signedPayload(array $payload, string $secret): array
{
    $body = json_encode($payload);

    return [$body, 'sha256='.hash_hmac('sha256', $body, $secret)];
}

test('a delivery with no signature is rejected', function () {
    githubApp();

    $this->call('POST', '/webhooks/github', [], [], [], [
        'HTTP_X-GitHub-Event' => 'ping',
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['zen' => 'hello']))->assertForbidden();
});

test('a delivery with a wrong signature is rejected', function () {
    githubApp();

    [$body] = signedPayload(['zen' => 'hello'], 'the-wrong-secret');

    $this->call('POST', '/webhooks/github', [], [], [], [
        'HTTP_X-GitHub-Event' => 'ping',
        'HTTP_X-Hub-Signature-256' => 'sha256='.str_repeat('0', 64),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertForbidden();
});

test('a correctly signed delivery is accepted', function () {
    githubApp();

    [$body, $signature] = signedPayload(['zen' => 'hello'], 'top-secret');

    $this->call('POST', '/webhooks/github', [], [], [], [
        'HTTP_X-GitHub-Event' => 'ping',
        'HTTP_X-Hub-Signature-256' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();
});

test('deliveries are rejected when no webhook secret is configured', function () {
    // No GitProviderApp at all - nothing to verify against, so nothing is trusted.
    config()->set('pulllens.webhooks.require_signature', true);

    $this->call('POST', '/webhooks/github', [], [], [], [
        'HTTP_X-GitHub-Event' => 'push',
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['repository' => ['id' => 1]]))->assertForbidden();
});

test('signature enforcement can only be relaxed outside production', function () {
    config()->set('pulllens.webhooks.require_signature', false);

    $this->call('POST', '/webhooks/github', [], [], [], [
        'HTTP_X-GitHub-Event' => 'ping',
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['zen' => 'hello']))->assertOk();
});
