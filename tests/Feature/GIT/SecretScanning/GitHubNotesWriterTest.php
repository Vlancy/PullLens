<?php

use App\Services\Git\Scanning\GitHubNotesWriter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

$base = 'api.github.com/repos/octocat/app/git';

it('creates the notes ref on the first note', function () use ($base) {
    Http::fake([
        "{$base}/ref/notes/gitleaks" => Http::response(['message' => 'Not Found'], 404),
        "{$base}/blobs" => Http::response(['sha' => 'blob1'], 201),
        "{$base}/trees" => Http::response(['sha' => 'tree1'], 201),
        "{$base}/commits" => Http::response(['sha' => 'notes1'], 201),
        "{$base}/refs" => Http::response(['ref' => 'refs/notes/gitleaks'], 201),
    ]);

    $sha = app(GitHubNotesWriter::class)->write('token', 'octocat', 'app', 'head-sha-1', 'note text');

    expect($sha)->toBe('notes1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/blobs') && base64_decode($r['content']) === 'note text' && $r['encoding'] === 'base64');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/trees') && ! isset($r['base_tree'])
        && $r['tree'] === [['path' => 'head-sha-1', 'mode' => '100644', 'type' => 'blob', 'sha' => 'blob1']]);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/commits') && $r['parents'] === [] && $r['tree'] === 'tree1');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/git/refs')
        && $r['ref'] === 'refs/notes/gitleaks' && $r['sha'] === 'notes1');
});

it('appends on top of the existing notes', function () use ($base) {
    Http::fake([
        "{$base}/ref/notes/gitleaks" => Http::response(['object' => ['sha' => 'notes0']]),
        "{$base}/commits/notes0" => Http::response(['sha' => 'notes0', 'tree' => ['sha' => 'tree0']]),
        "{$base}/blobs" => Http::response(['sha' => 'blob1'], 201),
        "{$base}/trees" => Http::response(['sha' => 'tree1'], 201),
        "{$base}/commits" => Http::response(['sha' => 'notes1'], 201),
        "{$base}/refs/notes/gitleaks" => Http::response(['ref' => 'refs/notes/gitleaks']),
    ]);

    app(GitHubNotesWriter::class)->write('token', 'octocat', 'app', 'head-sha-1', 'note text');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/trees') && $r['base_tree'] === 'tree0');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/git/commits') && $r['parents'] === ['notes0']);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/git/refs/notes/gitleaks')
        && $r['sha'] === 'notes1' && $r['force'] === false);
});

it('retries once when another writer moved the ref first', function () use ($base) {
    Http::fake([
        "{$base}/ref/notes/gitleaks" => Http::response(['object' => ['sha' => 'notes0']]),
        "{$base}/commits/notes0" => Http::response(['sha' => 'notes0', 'tree' => ['sha' => 'tree0']]),
        "{$base}/blobs" => Http::response(['sha' => 'blob1'], 201),
        "{$base}/trees" => Http::response(['sha' => 'tree1'], 201),
        "{$base}/commits" => Http::response(['sha' => 'notes1'], 201),
        "{$base}/refs/notes/gitleaks" => Http::sequence()
            ->push(['message' => 'Update is not a fast forward'], 422)
            ->push(['ref' => 'refs/notes/gitleaks']),
    ]);

    expect(app(GitHubNotesWriter::class)->write('token', 'octocat', 'app', 'head-sha-1', 'n'))->toBe('notes1');
    Http::assertSentCount(12);
});
