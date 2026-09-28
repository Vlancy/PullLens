# Gitleaks Secret Scanning — Design

Date: 2026-09-28
Status: Approved design, pending implementation plan

## Goal

Detect secrets (API keys, tokens, private keys, passwords) introduced by a pull request using the free, MIT-licensed gitleaks CLI, and report every hit in four places:

1. Inline PR review comments on the offending line.
2. Findings in PullLens (findings page, reports, resolve flows).
3. A dedicated GitHub check run that fails when secrets are found.
4. A git note on the PR head commit under `refs/notes/gitleaks`.

Every surface shows the secret **redacted**. PullLens must never re-leak the value it detected.

## Decisions

| Topic | Decision |
|---|---|
| Binary | gitleaks v8.x CLI (MIT). No `gitleaks-action`, no license key. |
| Scan scope | PR diff only — the added lines from the GitHub API patches. No clone. |
| Coupling | Separate job with its own check run, independent of AI reviews and the AI rate limit. |
| Storage | Reuse `pull_request_review_findings` with a `source` column; new `secret_scans` run table. |
| Resolution | Secret findings never auto-resolve because a file changed; only when a rescan no longer finds them. |

### Accepted limitation

Diff-only scanning inspects the PR's net diff against its base. A secret that is added in one commit and deleted in a later commit of the same PR never appears in that diff and will not be detected, even though it remains in the branch history. Full-history scanning is out of scope for this iteration.

## Architecture

### 1. Binary installation and configuration

- **Docker**: `docker/services/laravel/Dockerfile` base stage downloads a pinned gitleaks release tarball for the build architecture (`TARGETARCH` amd64/arm64), verifies its sha256, and installs it to `/usr/local/bin/gitleaks`. The version and checksums are build args at the top of the stage.
- **Source installs**: `install.sh` installs the same pinned version for linux/darwin × amd64/arm64 into a user-writable bin directory when `gitleaks` is not already on `PATH`, with the same checksum verification. `INSTALL.md` and `DOCKER.md` document it.
- **Config**: `config/pulllens.php` gains a `secret_scanning` block:
  - `binary` — env `GITLEAKS_BINARY`, default `gitleaks`.
  - `timeout` — env `GITLEAKS_TIMEOUT`, default 60 seconds.
  - Both are added to `.env.example`.
- **Missing binary**: if the binary cannot be executed, the scan is recorded with status `skipped`, a warning is logged, and no check run is created. The job does not fail or retry.

### 2. Trigger

- New column `git_repositories.secret_scanning_enabled` (boolean, default `true`).
- `PullRequestEventHandler` dispatches `ScanPullRequestSecrets($pullRequestId, $headSha)` when the action introduces code (opened, synchronize, reopened — a new `PullRequestWebhookAction::introducesCode()`; the existing `changesHeadCommit()` covers synchronize only), the head sha is non-empty, and the repository has `secret_scanning_enabled`.
- This is independent of `reviews_enabled`, `ReviewTriggerPolicy`, and the `ai-reviews` rate limiter.
- `ScanPullRequestSecrets` is `ShouldQueue` + `ShouldBeUnique` keyed on `pullRequestId:headSha`. If a `secret_scans` row with status `completed` already exists for that PR and head sha, it returns immediately. Its `timeout` must stay below the queue connection's `retry_after`, which `QueueConfigurationTest` enforces.

### 3. Scanning (`App\Services\Git\SecretScanning\SecretScanner`)

Following the repository's Repository + Service pattern, the job orchestrates. The work lives in small single-purpose services:

- **`PatchAddedLinesExtractor`** turns a unified-diff patch into the list of added lines, each paired with its line number in the new file. Removed and context lines are dropped. It is pure and has no I/O.
- **`GitleaksRunner`** takes a temp directory and optional config/ignore file contents. It runs `gitleaks dir <dir> --redact --no-banner --exit-code 0 --report-format json --report-path <tmp>/report.json` through the Laravel `Process` facade with the configured timeout. If `.gitleaks.toml` is present it passes `--config`, and if `.gitleaksignore` is present it passes `--gitleaks-ignore-path`. It returns parsed `GitleaksHit` DTOs holding rule id, description, file, line, redacted match and entropy. (The gitleaks fingerprint is not used for dedupe, because in `dir` mode it contains the line number.) The gitleaks version comes from `gitleaks version` and is cached for the process lifetime.
- **`SecretScanner`**:
  1. Fetches PR files through `GitHubApiClient::pullRequestFiles`. It does **not** apply `filterReviewableFiles`, because secrets often hide in lockfiles, dist output and vendored config.
  2. Fetches `.gitleaks.toml` and `.gitleaksignore` from the PR's **target branch** through `fetchFileContent`, if present. It never reads them from the PR head, because a pull request could otherwise allowlist the secret it adds.
  3. For each file with a patch, writes a mirror file into a unique temp directory under `storage/app/secret-scans/<uuid>/`. Line *N* of the mirror holds the content of added line *N*, and every other line is blank. Gitleaks's reported line number is therefore the real new-file line number, and no remapping is needed.
  4. Runs `GitleaksRunner`, strips the temp-dir prefix from reported paths, and returns the hits.
  5. Deletes the temp directory in `finally`.

Files without a patch (binary files or GitHub-truncated large patches) are skipped and counted in the scan metadata.

### 4. Storage

**New table `secret_scans`:**

| Column | Type |
|---|---|
| id | uuid pk |
| pull_request_id | uuid fk → pull_requests, cascade |
| git_repository_id | uuid fk → git_repositories, cascade |
| head_sha | string(40) |
| status | string(20) — `running`, `completed`, `failed`, `skipped` (enum `SecretScanStatus`) |
| findings_count | unsigned int, default 0 |
| files_scanned | unsigned int, default 0 |
| files_skipped | unsigned int, default 0 |
| check_run_id | unsigned bigint, nullable |
| notes_commit_sha | string(40), nullable |
| gitleaks_version | string(40), nullable |
| duration_ms | unsigned int, nullable |
| error | text, nullable |
| timestamps | |

Index: `(pull_request_id, head_sha)`.

**Changes to `pull_request_review_findings`:**

- `pull_request_review_id` becomes nullable.
- Add `secret_scan_id` — nullable uuid fk → `secret_scans`, cascade on delete.
- Add `source` — string(20), default `ai`. Enum `FindingSource` has `Ai` and `Gitleaks`. Existing rows backfill to `ai` through the default.
- Index `(pull_request_id, source)`.

**Mapping a hit to a finding:**

| Field | Value |
|---|---|
| source | `gitleaks` |
| title | `Secret detected: {rule description}` |
| severity | `critical` |
| category | `security` |
| file / line | from the hit |
| confidence | `1.0` |
| dedupe_key | `gitleaks:{rule_id}:{file}:{content_hash}` — `content_hash` is the first 16 hex chars of `hash_hmac('sha256', trimmed added-line content, app.key)`. It is computed in memory from the extractor output, never stored raw, and is stable when the line moves. |
| explanation | Rule id, redacted match, and a reminder that the value is now in git history |
| suggested_fix | Rotate/revoke the credential, remove it from the code, load it from environment or a secret store |

**Report queries**:
- `OverviewReportService::findings()` and `LeaderboardReportService::findingTotals()` inner-join `pull_request_reviews` and would silently drop gitleaks rows. They are changed to join the pull request on `f.pull_request_id`.
- `DailyActivityReportService::findingsByDate()` left-joins the review instead and dates each finding by `COALESCE(rev.reviewed_at, f.created_at)`.
- `DeveloperMetricsReportService::findingBreakdownByAuthor()`, which feeds the seniority score from AI review history, is restricted to `source = ai`.

**Null-review audit**: every code path that reads `$finding->review` or joins `pull_request_reviews` from findings (findings page and services, reports, dashboard, admin resolve controllers, `ReviewPullRequest` dedupe-key loading, reaction sync, dispute/reply jobs) is checked and guarded. AI-only flows filter to `source = ai` where secret findings do not belong. Examples are the previous-review dedupe keys fed to the AI and the AI dispute flow.

### 5. Outputs

All four outputs are produced by `ScanPullRequestSecrets` after findings are persisted. A failure of any one output is logged and does not prevent the others.

1. **Check run** — the job creates `PullLens / Secrets` (`in_progress`) at the start through `createCheckRun`, stores `check_run_id`, and completes it through `updateCheckRun`. The conclusion is `failure` when findings_count > 0, otherwise `success`, and `neutral` when the scan is skipped for a missing binary after creation. Findings become annotations (level `failure`, redacted message), batched to GitHub's 50-per-request limit.
2. **Inline PR comments** — when there are new findings, the job posts one PR review with event `COMMENT`. It has a short summary body ("N possible secrets detected — rotate them, removing is not enough") plus an inline comment per finding on its file and line, using the existing API client methods. Findings whose `dedupe_key` was already posted on this PR (`is_posted = true`) are not posted again. `is_posted` and `provider_comment_id` are set as for AI findings. The job never approves or requests changes.
3. **Findings in PullLens** — the rows from §4. The findings page gets a `source` filter (All / AI review / Secrets) in `IndexFindingsRequest`, the findings service, and `findings/index.tsx`, plus a "Secret" badge on gitleaks rows.
4. **git notes** — a new `GitHubNotesWriter` service uses the Git Data API. It runs only when findings_count > 0:
   1. `GET /git/ref/notes/gitleaks`. A 404 means no notes yet.
   2. If the ref exists, read its commit to get the parent sha and base tree.
   3. `POST /git/blobs` with the note text: scan id, gitleaks version, timestamp, and one redacted line per finding (`file:line rule`).
   4. `POST /git/trees` with `base_tree` = the previous notes tree and one entry whose path is the head sha (flat fanout, which git reads).
   5. `POST /git/commits` with the new tree, the parent (if any), and message `gitleaks: notes for {sha}`.
   6. `PATCH /git/refs/notes/gitleaks` (fast-forward, `force: false`), or `POST /git/refs` for the first note. On a 422 conflict, restart from step 1 once.
   7. Store `notes_commit_sha` on the scan.

   The GitHub App manifest already requests `contents: write`, so no new permission is needed. `INSTALL.md` explains how to read notes: `git fetch origin refs/notes/gitleaks:refs/notes/gitleaks && git log --notes=gitleaks`. The GitHub web UI does not display notes.

### 6. Resolution

- `CheckFindingResolutions` ignores findings with `source = gitleaks`.
- After a scan of a new head completes, open gitleaks findings on the PR whose `dedupe_key` is not among the new hits are marked resolved with a new resolution type `FindingResolutionType::SecretRemoved`. A reply is posted in their comment thread (when `provider_comment_id` is set): "No longer in the diff, but still in this branch's git history — rotate this credential if you have not already."
- Manual resolve (admin single and bulk) works unchanged.

### 7. Settings UI

- `GitRepository` casts `secret_scanning_enabled` to boolean.
- `UpdateGitRepositorySettingsRequest` validates it as boolean.
- `GitRepositorySettingsController` exposes it in the edit props and persists it on update.
- `git-repository-settings.tsx` gets a "Secret scanning" switch with helper text: "Scan every pull request diff for leaked credentials with gitleaks. Runs without AI and without AI cost."

## Error handling

| Situation | Behaviour |
|---|---|
| Binary missing or not executable | scan `skipped`, warning logged, no check run, no retry |
| gitleaks times out or exits non-zero | scan `failed`, error stored, check run completed `neutral` with the error summary, job retries once (`tries = 2`) |
| GitHub files fetch fails | scan `failed`, retried by the queue |
| Comment, check or notes API failure | logged per output; the other outputs still run; the scan is still `completed` |
| Invalid JSON report | scan `failed` with the parse error |

Redaction is guaranteed by passing `--redact` and by only ever using gitleaks's `Match` and `Secret` fields as returned under redaction. No raw patch content is copied into comments, notes, annotations or logs.

## Testing (Pest, TDD)

- `PatchAddedLinesExtractor`: added, removed and context lines, multiple hunks, new file, `\ No newline at end of file`.
- `GitleaksRunner`: builds the correct command (with and without config/ignore files) and parses a fixture JSON report, using `Process::fake()`. It returns an empty list on an empty report and throws on a timeout.
- `ScanPullRequestSecrets` (`Http::fake()` + `Process::fake()`):
  - Persists the scan and findings with the correct mapping.
  - Creates and completes the check run with annotations.
  - Posts the review with inline comments.
  - Writes git notes for both a first note and an existing ref.
  - Skips a head that was already scanned.
  - Does not repost dedupe keys that were already posted.
  - Resolves findings that disappear on a rescan and posts the reply.
  - Handles a missing binary by skipping.
  - Retries once on a notes 422 race.
- `PullRequestEventHandler`: dispatches the scan only when enabled and the head changed, independent of `reviews_enabled`.
- `CheckFindingResolutions`: leaves gitleaks findings untouched.
- Null-review audit: the findings page, reports and admin resolve work with gitleaks findings present.
- Settings: the toggle is validated, persisted and exposed.
- Findings page: the source filter.
- Integration (skipped unless the real `gitleaks` binary is on `PATH`): scanning a mirror file containing a fake AWS key yields one redacted hit on the correct line.

## Out of scope

- Scanning full PR commit history or whole repositories.
- Scheduled scans.
- Non-GitHub providers.
- Secret validity checking or automatic revocation.
