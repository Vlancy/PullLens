<?php

namespace App\Services\Git;

use App\Models\GIT\GitAccount;
use App\Models\GIT\GitProviderApp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitHubApiClient
{
    private const API_BASE = 'https://api.github.com';

    private const PER_PAGE = 100;

    /**
     * Cap pagination so an unexpected response cannot loop indefinitely.
     */
    private const MAX_PAGES = 20;

    /**
     * List the GitHub App installations the account's user token can access.
     *
     * Covers both the personal account and any organization the user belongs to.
     *
     * @return array<int, array<string, mixed>>
     */
    public function installations(GitAccount $account): array
    {
        return $this->paginate($account, '/user/installations', 'installations');
    }

    /**
     * List repositories the user can reach through a specific installation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function installationRepositories(GitAccount $account, int $installationId): array
    {
        return $this->paginate(
            $account,
            "/user/installations/{$installationId}/repositories",
            'repositories',
        );
    }

    /**
     * List ALL installations of the GitHub App using the App's own JWT credentials.
     * Returns every installation regardless of which user accounts are connected.
     *
     * @return array<int, array<string, mixed>>
     */
    public function appInstallations(GitProviderApp $app): array
    {
        $jwt = $this->buildAppJwt((string) $app->app_id, (string) $app->private_key);

        return $this->paginate($jwt, '/app/installations', null);
    }

    /**
     * List repositories accessible through a specific installation using an installation token.
     *
     * @return array<int, array<string, mixed>>
     */
    public function appInstallationRepositories(GitProviderApp $app, int $installationId): array
    {
        $token = $this->installationToken($app, $installationId);

        return $this->paginate($token, '/installation/repositories', 'repositories');
    }

    /**
     * Fetch the authenticated GitHub App's metadata using a JWT.
     *
     * @return array<string, mixed>
     */
    public function getApp(GitProviderApp $app): array
    {
        $jwt = $this->buildAppJwt((string) $app->app_id, (string) $app->private_key);

        return Http::withToken($jwt)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->get(self::API_BASE.'/app')
            ->throw()
            ->json();
    }

    /**
     * Exchange a GitHub App private key for a short-lived installation access token.
     *
     * The returned token authenticates as the App installation (appears as
     * "{app-name}[bot]" on GitHub) rather than as the connected user account.
     * Tokens are valid for one hour.
     */
    public function installationToken(GitProviderApp $app, int $installationId): string
    {
        $jwt = $this->buildAppJwt((string) $app->app_id, (string) $app->private_key);

        $response = Http::withToken($jwt)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->post(self::API_BASE."/app/installations/{$installationId}/access_tokens");

        return (string) $response->json('token', '');
    }

    /**
     * List branches for a repository identified by owner and name.
     * Accepts a GitAccount (OAuth token) or a plain string (installation token).
     *
     * @return array<int, array<string, mixed>>
     */
    public function branches(GitAccount|string $account, string $owner, string $repo): array
    {
        return $this->paginate($account, "/repos/{$owner}/{$repo}/branches", null);
    }

    /**
     * Fetch a single pull request by number.
     *
     * @return array<string, mixed>
     */
    public function pullRequest(GitAccount $account, string $owner, string $repo, int $number): array
    {
        return $this->request($account)
            ->get(self::API_BASE."/repos/{$owner}/{$repo}/pulls/{$number}")
            ->throw()
            ->json();
    }

    /**
     * List files changed in a pull request (with patches).
     *
     * @return array<int, array<string, mixed>>
     */
    public function pullRequestFiles(GitAccount $account, string $owner, string $repo, int $number): array
    {
        return $this->paginate($account, "/repos/{$owner}/{$repo}/pulls/{$number}/files", null);
    }

    /**
     * List commits in a pull request.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pullRequestCommits(GitAccount $account, string $owner, string $repo, int $number): array
    {
        return $this->paginate($account, "/repos/{$owner}/{$repo}/pulls/{$number}/commits", null);
    }

    /**
     * Add users to the pull request's requested reviewers list.
     *
     * @param  array<int, string>  $reviewers  GitHub logins to request
     * @return array<string, mixed>
     */
    public function requestReviewers(
        GitAccount|string $account,
        string $owner,
        string $repo,
        int $number,
        array $reviewers,
    ): array {
        return $this->request($account)
            ->post(self::API_BASE."/repos/{$owner}/{$repo}/pulls/{$number}/requested_reviewers", [
                'reviewers' => $reviewers,
            ])
            ->throw()
            ->json();
    }

    /**
     * Merge a pull request using the specified merge method.
     *
     * @return array<string, mixed>
     */
    public function mergePullRequest(
        GitAccount|string $account,
        string $owner,
        string $repo,
        int $number,
        string $mergeMethod = 'merge',
    ): array {
        return $this->request($account)
            ->put(self::API_BASE."/repos/{$owner}/{$repo}/pulls/{$number}/merge", [
                'merge_method' => $mergeMethod,
            ])
            ->throw()
            ->json();
    }

    /**
     * Update a pull request's metadata (e.g. body/description).
     *
     * @param  array<string, mixed>  $fields  Fields to patch (e.g. ['body' => '...'])
     * @return array<string, mixed>
     */
    public function updatePullRequest(
        GitAccount|string $account,
        string $owner,
        string $repo,
        int $number,
        array $fields,
    ): array {
        return $this->request($account)
            ->patch(self::API_BASE."/repos/{$owner}/{$repo}/pulls/{$number}", $fields)
            ->throw()
            ->json();
    }

    /**
     * Apply labels to a pull request (issue endpoint). Only existing repo labels are applied.
     *
     * @param  array<int, string>  $labels
     * @return array<int, array<string, mixed>>
     */
    public function applyLabels(
        GitAccount|string $account,
        string $owner,
        string $repo,
        int $issueNumber,
        array $labels,
    ): array {
        return $this->request($account)
            ->post(self::API_BASE."/repos/{$owner}/{$repo}/issues/{$issueNumber}/labels", [
                'labels' => $labels,
            ])
            ->throw()
            ->json();
    }

    /**
     * Submit a pull request review (APPROVE, COMMENT, or REQUEST_CHANGES).
     *
     * @return array<string, mixed>
     */
    public function postPullRequestReview(
        GitAccount|string $account,
        string $owner,
        string $repo,
        int $number,
        string $body,
        string $event,
    ): array {
        return $this->request($account)
            ->post(self::API_BASE."/repos/{$owner}/{$repo}/pulls/{$number}/reviews", [
                'body' => $body,
                'event' => $event,
            ])
            ->throw()
            ->json();
    }

    /**
     * Post an inline review comment on a specific file line in a pull request.
     *
     * @return array<string, mixed>
     */
    public function postReviewComment(
        GitAccount|string $account,
        string $owner,
        string $repo,
        int $number,
        string $commitId,
        string $path,
        int $line,
        string $body,
        string $side = 'RIGHT',
    ): array {
        return $this->request($account)
            ->post(self::API_BASE."/repos/{$owner}/{$repo}/pulls/{$number}/comments", [
                'body' => $body,
                'commit_id' => $commitId,
                'path' => $path,
                'line' => $line,
                'side' => $side,
            ])
            ->throw()
            ->json();
    }

    /**
     * Reply to an existing inline pull request review comment thread.
     * GitHub treats a reply as a new comment in the same thread without requiring
     * commit_id, path, or line — only the parent comment ID.
     *
     * @return array<string, mixed>
     */
    public function replyToReviewComment(
        GitAccount|string $account,
        string $owner,
        string $repo,
        int $pullNumber,
        int $inReplyTo,
        string $body,
    ): array {
        return $this->request($account)
            ->post(self::API_BASE."/repos/{$owner}/{$repo}/pulls/{$pullNumber}/comments", [
                'body' => $body,
                'in_reply_to' => $inReplyTo,
            ])
            ->throw()
            ->json();
    }

    /**
     * Post a comment on a pull request issue thread (general comment, not inline).
     *
     * @return array<string, mixed>
     */
    public function postIssueComment(
        GitAccount|string $account,
        string $owner,
        string $repo,
        int $number,
        string $body,
    ): array {
        return $this->request($account)
            ->post(self::API_BASE."/repos/{$owner}/{$repo}/issues/{$number}/comments", [
                'body' => $body,
            ])
            ->throw()
            ->json();
    }

    /**
     * Delete an issue (or pull request) comment by its comment ID.
     */
    public function deleteIssueComment(GitAccount|string $account, string $owner, string $repo, int $commentId): void
    {
        $this->request($account)
            ->delete(self::API_BASE."/repos/{$owner}/{$repo}/issues/comments/{$commentId}")
            ->throw();
    }

    /**
     * Fetch the decoded text content of a file from a repository.
     * Returns null when the file doesn't exist or the request fails.
     */
    public function fetchFileContent(
        GitAccount|string $auth,
        string $owner,
        string $repo,
        string $path,
        ?string $ref = null,
    ): ?string {
        $query = $ref !== null ? ['ref' => $ref] : [];
        $response = $this->request($auth)
            ->get(self::API_BASE."/repos/{$owner}/{$repo}/contents/{$path}", $query);

        if ($response->failed()) {
            return null;
        }

        $encoded = (string) $response->json('content', '');
        $decoded = base64_decode(str_replace(["\n", ' '], '', $encoded), strict: true);

        return $decoded !== false ? $decoded : null;
    }

    /**
     * Create a GitHub Check Run on a specific commit in "in_progress" state.
     * Requires a GitHub App installation token; fails silently with OAuth tokens.
     *
     * @return array<string, mixed>|null
     */
    public function createCheckRun(
        GitAccount|string $auth,
        string $owner,
        string $repo,
        string $headSha,
        string $name = 'PullLens',
    ): ?array {
        $response = $this->request($auth)
            ->post(self::API_BASE."/repos/{$owner}/{$repo}/check-runs", [
                'name' => $name,
                'head_sha' => $headSha,
                'status' => 'in_progress',
                'started_at' => now()->toIso8601String(),
            ]);

        return $response->successful() ? (array) $response->json() : null;
    }

    /**
     * Complete a Check Run with a conclusion, human-readable summary, and file annotations.
     * Annotations are sent in batches of 50 to stay within the GitHub API limit.
     *
     * @param  array<int, array<string, mixed>>  $annotations
     */
    public function updateCheckRun(
        GitAccount|string $auth,
        string $owner,
        string $repo,
        int $checkRunId,
        string $conclusion,
        string $title,
        string $summary,
        array $annotations = [],
    ): void {
        $this->request($auth)
            ->patch(self::API_BASE."/repos/{$owner}/{$repo}/check-runs/{$checkRunId}", [
                'status' => 'completed',
                'conclusion' => $conclusion,
                'completed_at' => now()->toIso8601String(),
                'output' => [
                    'title' => $title,
                    'summary' => $summary,
                    'annotations' => array_slice($annotations, 0, 50),
                ],
            ]);

        for ($offset = 50; $offset < count($annotations); $offset += 50) {
            $this->request($auth)
                ->patch(self::API_BASE."/repos/{$owner}/{$repo}/check-runs/{$checkRunId}", [
                    'output' => [
                        'title' => $title,
                        'summary' => $summary,
                        'annotations' => array_slice($annotations, $offset, 50),
                    ],
                ]);
        }
    }

    /**
     * List all reactions on an inline pull request review comment.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReviewCommentReactions(
        GitAccount|string $auth,
        string $owner,
        string $repo,
        int $commentId,
    ): array {
        $response = $this->request($auth)
            ->get(self::API_BASE."/repos/{$owner}/{$repo}/pulls/comments/{$commentId}/reactions");

        return $response->successful() ? (array) $response->json() : [];
    }

    /**
     * List webhooks on a repository. Returns an empty array if the token lacks admin access.
     *
     * @return array<int, array<string, mixed>>
     */
    public function repoWebhooks(GitAccount $account, string $owner, string $repo): array
    {
        $response = $this->request($account)
            ->get(self::API_BASE."/repos/{$owner}/{$repo}/hooks");

        if ($response->failed()) {
            return [];
        }

        return (array) $response->json();
    }

    /**
     * Create a webhook on a repository, subscribing to the events PullLens handles.
     *
     * @return array<string, mixed>
     */
    public function createRepoWebhook(
        GitAccount $account,
        string $owner,
        string $repo,
        string $webhookUrl,
        string $secret,
    ): array {
        return $this->request($account)
            ->post(self::API_BASE."/repos/{$owner}/{$repo}/hooks", [
                'name' => 'web',
                'active' => true,
                'events' => ['pull_request', 'pull_request_review_comment', 'issue_comment'],
                'config' => [
                    'url' => $webhookUrl,
                    'content_type' => 'json',
                    'secret' => $secret,
                    'insecure_ssl' => '0',
                ],
            ])
            ->throw()
            ->json();
    }

    /**
     * Update the events and URL of an existing repository webhook.
     *
     * @return array<string, mixed>
     */
    public function updateRepoWebhook(
        GitAccount $account,
        string $owner,
        string $repo,
        int $hookId,
        string $webhookUrl,
        string $secret,
    ): array {
        return $this->request($account)
            ->patch(self::API_BASE."/repos/{$owner}/{$repo}/hooks/{$hookId}", [
                'active' => true,
                'events' => ['pull_request', 'pull_request_review_comment', 'issue_comment'],
                'config' => [
                    'url' => $webhookUrl,
                    'content_type' => 'json',
                    'secret' => $secret,
                    'insecure_ssl' => '0',
                ],
            ])
            ->throw()
            ->json();
    }

    /**
     * Delete a repository webhook by its hook ID.
     */
    public function deleteRepoWebhook(GitAccount $account, string $owner, string $repo, int $hookId): void
    {
        $this->request($account)
            ->delete(self::API_BASE."/repos/{$owner}/{$repo}/hooks/{$hookId}")
            ->throw();
    }

    /**
     * Fetch every page of a GitHub list endpoint and flatten the results.
     *
     * @param  string|null  $key  Response key holding the list, or null for a bare array.
     * @return array<int, array<string, mixed>>
     */
    private function paginate(GitAccount|string $account, string $path, ?string $key): array
    {
        $items = [];
        $page = 1;

        do {
            $response = $this->request($account)
                ->get(self::API_BASE.$path, [
                    'per_page' => self::PER_PAGE,
                    'page' => $page,
                ])
                ->throw()
                ->json();

            $batch = $key !== null ? (array) data_get($response, $key, []) : (array) $response;
            $items = array_merge($items, $batch);
            $page++;
        } while (count($batch) === self::PER_PAGE && $page <= self::MAX_PAGES);

        return $items;
    }

    /**
     * Build an authenticated GitHub request.
     * Accepts a GitAccount (user OAuth token) or a plain token string (installation token).
     */
    private function request(GitAccount|string $account): PendingRequest
    {
        $token = $account instanceof GitAccount ? $account->access_token : $account;

        return Http::withToken($token)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);
    }

    /**
     * Build a signed RS256 JWT for GitHub App authentication.
     * The JWT is used only to exchange for an installation access token — it is never stored.
     */
    private function buildAppJwt(string $appId, string $privateKey): string
    {
        $encode = static fn (mixed $data): string => rtrim(
            strtr(base64_encode(is_string($data) ? $data : (string) json_encode($data)), '+/', '-_'),
            '='
        );

        $header = $encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $payload = $encode([
            'iat' => time() - 60,   // 60s in the past to tolerate clock skew
            'exp' => time() + 600,  // 10-minute maximum allowed by GitHub
            'iss' => $appId,
        ]);

        $unsigned = "{$header}.{$payload}";
        openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return "{$unsigned}.{$encode($signature)}";
    }
}
