# Projects, repository profiles and feature-grouped tasks — design

Date: 2026-11-15
Status: approved 2026-11-15

## Intent

The tasks board (`/tasks`) is a flat list of AI-extracted tasks. The owner wants
work grouped the way people talk about it: a **main feature** ("Auth system") with
its **subtasks** underneath ("Create register", "Fix bug in register", "Create
reset password").

Why: all three of —
- **progress** — how far along is each feature,
- **rework** — how much of a feature came back (fixes / reverts),
- **contribution** — who built what inside each feature,

and a **manager sees the top level** (features) without opening every task.

There is no Jira (or other tracker) holding epics today, so **the AI decides** the
grouping. A feature like "Auth system" often spans a backend and a frontend repo,
so grouping must be able to cross repositories — via **projects** the owner
defines.

## Scope — three pieces, built in order

1. **Projects** — named group of repositories.
2. **Repository profile tags** — AI-maintained, user-editable tags describing each
   repo (languages, frameworks, domain), given to the review AI as context.
3. **Features** — AI assigns every task to a feature scoped to the repo's project
   (or the repo alone when it has no project); `/tasks` shows features at top level.

Each piece is planned, built and shipped before the next starts. Piece 3 depends
on 1 and benefits from 2.

## Goals

- Manager opens `/tasks` and sees one row per feature with task count, done count,
  rework count, contributors, last activity; expands to see subtasks.
- Features collect tasks across all repos in the same project.
- Grouping happens automatically during the existing PR review — no extra AI call
  per PR.
- People with authority can correct the AI: rename / merge features, move a task.
  Manual corrections are never overwritten by later reviews.
- Historical tasks get grouped by a one-time backfill command.

## Non-goals

- Jira / Linear / tracker epic import (`TaskTrackerProvider` stays detection-only).
- Cross-repo task *links* (fixes/extends across repos). `TaskCandidateProvider`
  stays repo-scoped for links.
- Manual creation of tasks. Tasks still come only from PR reviews.
- Feature-level estimates, due dates, planning or roadmap features. This reports on
  delivered/in-flight work; it is not a planning tool.
- Nested features (feature → sub-feature). Two levels only: feature → task.

## Anti-goals (would be failure even if it "works")

- The AI producing near-duplicate features ("Auth", "Authentication", "Login
  system") with no easy way to merge — the manager view becomes noise.
- A manual correction silently reverted by the next review.
- Tasks counted twice in manager numbers.
- Contributors (scoped users) seeing tasks from repos they are not granted.
- Noticeable increase in review cost/latency.

## Constraints (from the existing project)

- Laravel + Inertia/React; Repository + Service pattern (see
  `LARAVEL_REPOSITORY_PATTERN.md`), UUID primary keys, enums with
  `label()/values()/options()/jsonSerialize()` like `TaskRelation`.
- Tasks are produced by `PullRequestReviewAgent` structured output and persisted by
  `TaskRecorder`; links by `TaskLinker`; previous-task context by
  `TaskCandidateProvider`. Feature assignment hooks into the same flow.
- Roles: Admin, Manager, Member, Contributor (`UserRole`). Contributor is scoped to
  granted repos via `git_repository_user`. Manager already has
  `ManageRepositories`.
- Ships only through the Docker image on a release tag; migrations must run cleanly
  on existing installs with existing task data.

## Approaches considered

**Who decides a task's feature**
- A. AI suggests, people can correct — **chosen**. Automatic, no backlog of
  unassigned tasks; corrections recorded with `source = manual` (same idea as task
  links' `source: ai|manual`).
- B. Manual assignment only — exact, but someone must group every task; manager
  view stays empty in practice.
- C. Jira epic / branch prefix — no tracker in use; branch naming inconsistent.
  Rejected for now.

**Scope of a feature**
- Per-repo only — cleaner for the AI, but splits "Auth system" into backend and
  frontend rows.
- Global setting "match all repos / separate" — one switch can't fit an org with
  several products.
- **Projects (chosen)** — owner groups repos into projects; features live inside a
  project. Repo with no project = its own scope. A repo belongs to **at most one
  project** so tasks never count twice.

**Repo profile tags refresh**
- **On connect + periodic/on-demand (chosen)** — GitHub language stats + a look at
  manifest files (`composer.json`, `package.json`, `go.mod`, README …).
- Re-derive on every PR — extra AI cost for little change. Rejected.

## Design

### Piece 1 — Projects

Data:
- `projects`: `id` (uuid), `name` (unique), `description` (nullable text),
  timestamps.
- `git_repositories.project_id` nullable FK → `projects`, `nullOnDelete`.
  One-project-per-repo is enforced by the column itself.

Behaviour:
- Settings page "Projects" (permission `ManageRepositories`): list, create, edit
  name/description, assign/unassign repos (multi-select of connected repos), delete.
- Deleting a project unassigns its repos; what happens to its features is defined
  in piece 3.
- Repo **joins** a project (incl. the common case: piece 3 already ran with no
  projects): its repo-scoped features are promoted into the project scope
  automatically, no AI. Slug collision with an existing project feature → merged
  (`merged_into_id`), manual names win over AI names. *(Designer's call.)*
- Repo **leaves** a project: its tasks move to repo-scoped copies of their current
  features (same name/slug/source), so the board never shows them under a project
  they no longer belong to. *(Designer's call.)*
- Project shown on the repository list and repo settings page.
- Project description is passed to the review AI as context (piece 3).

### Piece 2 — Repository profile tags

Data:
- `git_repository_tags`: `id`, `git_repository_id` FK cascade, `name`
  (lowercase slug, e.g. `laravel`), `kind` enum (`language`, `framework`, `domain`,
  `other`), `source` (`ai` | `manual`), timestamps; unique
  (`git_repository_id`, `name`).
- `git_repositories.profile_refreshed_at` nullable timestamp.

Behaviour:
- Job `RefreshRepositoryProfile`: GitHub languages endpoint + raw fetch of known
  manifest files / README (reuse `GitHubApiClient::fetchRawFileContent()`), one
  small AI call returning tags with kinds. New `AiOperation` case for cost
  tracking. Replaces only `source = ai` tags; `manual` tags are never touched.
  A tag a user deletes must not be re-added by the AI (store as a suppressed tag
  or keep a `dismissed` flag — builder's call).
- Triggered: on repo connect, from a "Refresh" button on repo settings, and by a
  scheduled command (monthly) for repos with stale `profile_refreshed_at`.
- Repo settings page: tag chips, add / remove, refresh button, last refreshed time.
- Review prompt receives the repo's tags (and project's other repos' tags in
  piece 3) as trusted context.

### Piece 3 — Features and subtasks

Data:
- `task_features`: `id` (uuid), `project_id` nullable FK, `git_repository_id`
  nullable FK — exactly one of the two set (scope), `name`, `slug` (stable key the
  AI references), `description` nullable, `source` (`ai` | `manual`),
  `merged_into_id` nullable self-FK, timestamps; unique (scope, `slug`).
- `pull_request_tasks.task_feature_id` nullable FK `nullOnDelete`,
  `feature_source` (`ai` | `manual`), indexed.

Scope resolution: a task's scope is its repo's `project_id` if set, else its repo.

AI assignment (inside the existing review call — no extra request):
- New `FeatureCandidateProvider` supplies the scope's existing features (slug,
  name, short description, recent task count), capped like
  `TaskCandidateProvider`, plus project name/description and repo tags.
- Each task in `PullRequestReviewAgent` schema gains `feature`:
  `{ existing_slug | null, new_name | null, new_description | null }`. Prompt
  rules: prefer an existing feature; create new only for genuinely new capability
  areas; names are product-level ("Auth system"), not task-level ("Fix register");
  chores/tests/docs go to the feature they support, or to "Maintenance" when none.
- `TaskRecorder` resolves: existing slug → that feature (following
  `merged_into_id`); new name → create feature (`source ai`), dedupe by
  normalised slug in scope. If `feature_source = manual` on the task, skip.
- Manual corrections must survive re-keying: `TaskRecorder` deletes tasks a
  re-review no longer returns. Before deleting a task with
  `feature_source = manual`, carry its feature (and manual flag) to the new task
  in the same PR with the greatest file overlap (fallback: title similarity).
  *(Designer's call.)*

Corrections (permission `ManageRepositories`, i.e. Admin + Manager):
- Rename / edit description of a feature → `source` becomes `manual`.
- Merge feature A into B → A's tasks move to B, A gets `merged_into_id = B` and
  is hidden; AI references to A's slug resolve to B.
- Move a task to another feature in its scope (or create a new one) →
  `feature_source = manual`.

Backfill (owner chose: UI trigger):
- Settings → Projects shows "Group existing tasks" per project / unassigned repo,
  with a preview ("N tasks, ~X AI calls"), confirm step, and progress state while
  the queued job runs. Re-runnable. Never runs automatically on upgrade.
- Same logic also exposed as artisan command
  `tasks:group-features {--repository=} {--project=}`: batches
  ungrouped tasks per scope oldest → newest, AI assigns using titles/descriptions
  only (no diffs). Queued, idempotent, skips `manual`. Separate `AiOperation`.

`/tasks` board:
- Default view: features table — name, scope (project/repo), contributors
  (avatars), last activity, and three counts, each task counted once
  *(designer's call)*:
  - **Tasks** — all tasks in the feature.
  - **Shipped** — tasks with `delivered_at` set, any status (a shipped task later
    fixed is still shipped).
  - **Came back** — tasks whose status is `reworked` or `reverted` (the damaged
    work, not the fix task). The fix task itself counts under Tasks/Shipped like
    any other. `revised` (extended) is not rework. Expand row → existing task rows for that feature, with link
  badges ("fixes Create register").
- Toggle back to the current flat list. Existing filters (repo, author, type,
  status, period, search) apply to both; a feature row shows only when it has
  matching tasks, and its counts reflect the filters.
- Contributor users: features rolled up from granted repos only.
- Tasks with no feature (old data before backfill) under an "Ungrouped" row.
- Feature detail actions (rename, merge, move task) for Admin/Manager.

Reports: the tasks report gets a "by feature" breakdown. Builder may defer this
to a follow-up if it grows the piece; the board is the must-have.

### Deleting

- Delete project: repos unassigned; project features stay readable (shown under a
  "former project" label) or are reassigned to repo scope — builder's call, but
  tasks must never lose their feature silently.
- Delete repo: existing cascades remove its tasks; empty AI features are pruned.

## Left to the builder

- Exact UI layout/components, within existing design of `/tasks` and settings.
- Prompt wording, candidate caps, and slug normalisation.
- How dismissed AI tags are stored.
- Cron cadence for profile refresh (monthly suggested).
- Query strategy for feature rollups (aggregate query vs denormalised counters),
  as long as filters stay correct.
- Test coverage per project standard (feature tests per piece).
