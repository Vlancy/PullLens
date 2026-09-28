<?php

use App\Services\Git\GitHubApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('reads the whole file through the raw media type, at the given ref', function () {
    $big = str_repeat('{"name":"pkg","version":"1.0.0"},', 50_000); // ~1.6 MB, over the JSON API's 1 MB limit
    Http::fake(['api.github.com/repos/octocat/app/contents/package-lock.json?ref=head-sha-1' => Http::response($big)]);

    $content = app(GitHubApiClient::class)->fetchRawFileContent('token', 'octocat', 'app', 'package-lock.json', 'head-sha-1');

    expect($content)->toBe($big);
    Http::assertSent(fn (Request $r) => $r->hasHeader('Accept', 'application/vnd.github.raw+json'));
});

it('encodes each path segment', function () {
    Http::fake(['api.github.com/repos/octocat/app/contents/deploy%20files/k8s/app.yaml?ref=main' => Http::response('kind: Pod')]);

    expect(app(GitHubApiClient::class)->fetchRawFileContent('token', 'octocat', 'app', 'deploy files/k8s/app.yaml', 'main'))->toBe('kind: Pod');
});

it('returns null when the file does not exist at that ref', function () {
    Http::fake(['api.github.com/repos/octocat/app/contents/*' => Http::response(['message' => 'Not Found'], 404)]);

    expect(app(GitHubApiClient::class)->fetchRawFileContent('token', 'octocat', 'app', 'composer.lock', 'main'))->toBeNull();
});

it('throws when the request fails for a reason other than the file being missing', function () {
    Http::fake(['api.github.com/repos/octocat/app/contents/*' => Http::response(['message' => 'Internal Server Error'], 500)]);

    expect(fn () => app(GitHubApiClient::class)->fetchRawFileContent('token', 'octocat', 'app', 'composer.lock', 'main'))
        ->toThrow(RequestException::class);
});
