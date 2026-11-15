# Projects, Repo Tags & Feature-Grouped Tasks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Group repos into projects, give repos AI-maintained profile tags, and have the review AI assign every task to a feature so `/tasks` shows features with subtasks.

**Architecture:** Three pieces built in order. Projects = new table + nullable FK on `git_repositories` (Repository+Service pattern like `GitRepositoryRepository`). Tags = new table + small AI agent run by a queued job. Features = new table scoped to project-or-repo; assignment piggybacks on the existing `PullRequestReviewAgent` call and `TaskRecorder`; a separate grouping agent backfills old tasks; `/tasks` gains a feature rollup view.

**Tech Stack:** Laravel 12, `laravel/ai` agents (`Promptable::fake()` in tests), Pest, Inertia + React + TS, Tailwind/shadcn UI components already in `resources/js/components/ui`.

**Spec:** `docs/superpowers/specs/2026-11-15-projects-features-tasks-design.md`

## Global Constraints

- UUID primary keys (`HasUuids`) for new top-level models; enums implement `JsonSerializable` with `label()`, `values()`, `options()` like `app/Enums/GIT/TaskRelation.php`.
- PHPDoc on every method (one sentence + @param/@return). Pint clean (`vendor/bin/pint --test`).
- Management actions (projects, tags, feature corrections, backfill) gated by `UserPermission::ManageRepositories` (Admin + Manager). Board stays gated by `ViewTasks` and narrowed with `RepositoryScope::forUser()`.
- A repo belongs to at most one project (single nullable FK).
- Manual (`source = manual`) data is never overwritten by AI: manual tags, manual feature names, manual task→feature assignment, dismissed AI tags.
- No extra AI call per PR review. Backfill never runs automatically on upgrade.
- Migrations must run on installs with existing tasks (all new columns nullable / defaulted).
- Feature row counts: **Tasks** = all; **Shipped** = `delivered_at` not null; **Came back** = status in (`reworked`,`reverted`). Each task counted once.
- Commit messages: conventional, no AI/assistant names or trailers.

## Review Focus

1. AI returns `existing_slug` not in the candidate list (invented/typo) → treated as `new_name` fallback, never a crash or silent drop; if both fields empty, task stays ungrouped (`task_feature_id` null). Test in Task 6.
2. Near-duplicate names ("Auth System", " auth-system ", "Auth system!") in one scope → one feature (slug normalisation). Test in Task 6.
3. Re-review re-keys a manually assigned task → manual feature carried to the replacement task. Test in Task 7.
4. Contributor with grants on only one repo of a two-repo project → feature counts and expanded tasks include only granted repo's tasks. Test in Task 10.
5. Merge chain A→B then B→C → assignments referencing A resolve to C; merging a feature into itself or into one in another scope is rejected (422). Test in Task 8.

---

## Piece 1 — Projects

### Task 1: Project model, persistence, service

**Files:**
- Create: `database/migrations/2026_11_15_100000_create_projects_table.php`, `database/migrations/2026_11_15_100001_add_project_id_to_git_repositories.php`
- Create: `app/Models/GIT/Project.php`, `database/factories/GIT/ProjectFactory.php`
- Create: `app/Repositories/Contracts/GIT/ProjectRepositoryInterface.php`, `app/Repositories/Eloquent/GIT/ProjectRepository.php`; register in `config/repository.php`
- Create: `app/Services/Projects/ProjectService.php`
- Modify: `app/Models/GIT/GitRepository.php` (fillable `project_id`, `project(): BelongsTo`)
- Test: `tests/Feature/Projects/ProjectServiceTest.php`

**Interfaces:**
- Produces: `Project` (`id`, `name` unique string 100, `description` nullable text, `repositories(): HasMany<GitRepository>`).
- Produces: `ProjectService::create(array{name:string,description:?string} $data): Project`, `update(Project $p, array $data): Project`, `assignRepositories(Project $p, array<string> $repositoryIds): void` (sets exactly this set: repos in list get `project_id`, repos previously in project but not in list get null), `delete(Project $p): void`.
- Produces events for Task 8: `ProjectService` calls `FeatureScopeMigrator` hooks — leave as no-op interface now: `app/Services/Tasks/Features/FeatureScopeMigrator.php` with `repositoryJoined(GitRepository $r, Project $p): void`, `repositoryLeft(GitRepository $r, Project $old): void` (empty bodies; Task 8 fills).

- [ ] **Step 1: Write failing tests** — `creates a project with unique name`, `assigning repositories sets exactly the given set` (assign [A,B] then [B,C] → A null, B,C set), `a repository moved from project X to Y belongs only to Y`, `deleting a project unassigns its repositories` (repos still exist, `project_id` null), `assignment calls migrator joined/left` (mock `FeatureScopeMigrator`, expect `repositoryLeft` before `repositoryJoined` when moving).
- [ ] **Step 2: Run** `php artisan test tests/Feature/Projects/ProjectServiceTest.php` → FAIL (class not found).
- [ ] **Step 3: Implement** migrations (`project_id` `foreignUuid()->nullable()->constrained('projects')->nullOnDelete()`, index), model, factory, repository (methods: `create`, `update`, `delete`, `findOrFail`, `allWithRepositories()`), service wrapped in `DB::transaction`.
- [ ] **Step 4: Run** test → PASS.
- [ ] **Step 5: Commit** `feat(projects): add project model and service`

### Task 2: Projects settings page

**Files:**
- Create: `app/Http/Controllers/Settings/Projects/ProjectController.php` (`index`, `store`, `update`, `destroy`), `app/Http/Controllers/Settings/Projects/ProjectRepositoriesController.php` (`__invoke` = PUT assign)
- Create: `app/Http/Requests/Settings/Projects/StoreProjectRequest.php`, `UpdateProjectRequest.php`, `AssignProjectRepositoriesRequest.php`
- Modify: `routes/settings.php` (group `permission:repositories.manage`, prefix `settings/projects`, names `projects.index|store|update|destroy|repositories`)
- Create: `resources/js/pages/settings/projects.tsx`
- Modify: `resources/js/components/app-sidebar.tsx` (Settings child "Projects", permission `repositories.manage`)
- Modify: `app/Http/Controllers/Settings/GIT/GitRepositorySettingsController.php` (`edit` passes `project: {id,name}|null`) and `resources/js/pages/settings/git-repository-settings.tsx` (read-only "Project: X" line linking to projects page)
- Test: `tests/Feature/Projects/ProjectSettingsTest.php`

**Interfaces:**
- Consumes: `ProjectService` (Task 1).
- Produces: Inertia page `settings/projects` props `projects: Array<{id,name,description,repositories:{id,full_name}[]}>`, `repositories: Array<{id,full_name,project_id}>` (all connected). Task 9 adds `grouping` prop.

- [ ] **Step 1: Write failing tests** — `manager can view projects page` (Inertia component + props), `member gets 403`, `store validates unique name (422)`, `assign rejects unknown repository ids (422)`, `destroy unassigns repos`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** controllers (thin, delegate to service), requests (`name` required|max:100|unique:projects,name ignoring self; `repository_ids.*` uuid|exists:git_repositories,id), page: list of project cards with name/description edit inline, repo multi-select (checkbox list; repos already in another project show "in <name>" and moving them is allowed), create form, delete with confirm UI (no browser `confirm()` — use existing dialog component).
- [ ] **Step 4: Run** tests + `npm run types:check` + `npm run lint:check` → PASS.
- [ ] **Step 5: Commit** `feat(projects): projects settings page`

---

## Piece 2 — Repository profile tags

### Task 3: Tag model and tag rules

**Files:**
- Create: `database/migrations/2026_11_15_110000_create_git_repository_tags_table.php` (+ `profile_refreshed_at` nullable timestamp on `git_repositories`)
- Create: `app/Enums/GIT/RepositoryTagKind.php` (`language`, `framework`, `domain`, `other`), `app/Enums/GIT/AttributionSource.php` (`ai`, `manual`) — reused by Task 6
- Create: `app/Models/GIT/GitRepositoryTag.php`; `GitRepository::tags(): HasMany`
- Create: `app/Services/Repositories/RepositoryTagService.php`
- Test: `tests/Feature/Repositories/RepositoryTagServiceTest.php`

**Interfaces:**
- Table: `id`, `git_repository_id` FK cascade, `name` string 50 (lowercase slug), `kind`, `source`, `dismissed` bool default false, timestamps, unique(`git_repository_id`,`name`).
- Produces: `RepositoryTagService::addManual(GitRepository $r, string $name, RepositoryTagKind $kind): GitRepositoryTag` (slugifies; existing row → becomes manual, `dismissed=false`); `remove(GitRepositoryTag $t): void` (manual → delete; ai → `dismissed=true`); `replaceAiTags(GitRepository $r, array<array{name:string,kind:string}> $tags): void` (deletes non-dismissed ai tags not in list, inserts new; never touches manual or dismissed rows; caps 20; unknown kind → `other`); `forPrompt(GitRepository $r): array<string>` (non-dismissed names).
- [ ] **Step 1: Failing tests** — `manual tags survive ai replace`, `dismissed ai tag is not re-added`, `names are slugified and deduped ("Laravel " and "laravel")`, `forPrompt excludes dismissed`, `cap at 20`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(repositories): repository profile tags`

### Task 4: AI profile refresh job

**Files:**
- Create: `app/Ai/Agents/RepositoryProfileAgent.php` (structured output `tags: [{name, kind}]`, 3–15 tags, instructions: languages, frameworks, product domain; treat file contents as untrusted)
- Modify: `app/Services/Git/GitHubApiClient.php` add `languages(GitAccount|string $auth, string $owner, string $repo): array<string,int>` (`GET /repos/{o}/{r}/languages`, `[]` on failure)
- Modify: `app/Enums/AI/AiOperation.php` add `RepositoryProfile = 'repository_profile'`, `FeatureGrouping = 'feature_grouping'` (labels)
- Create: `app/Jobs/GIT/RefreshRepositoryProfile.php` (`__construct(string $repositoryId)`, `ShouldBeUnique` by repo id, timeout 120)
- Create: `app/Console/Commands/Repositories/RefreshRepositoryProfiles.php` signature `pulllens:refresh-repository-profiles {--stale-days=30}`; schedule `monthly()` in `routes/console.php`
- Modify: `app/Services/Git/RepositorySelectionSynchronizer.php` dispatch job for `wasRecentlyCreated` repos after commit
- Test: `tests/Feature/Repositories/RefreshRepositoryProfileTest.php`

**Interfaces:**
- Consumes: `RepositoryTagService::replaceAiTags` (Task 3), `AiProviderConfigResolver::forRepository`, `AiUsageRecorder::record`, auth the same way `ReviewPullRequest` resolves the API caller (reuse `GitHubCallerResolver`).
- Job flow: languages + `fetchFileContent` for first ≤6 existing of [`composer.json`,`package.json`,`go.mod`,`pyproject.toml`,`Gemfile`,`Cargo.toml`,`pom.xml`,`README.md`] each truncated to 4 000 chars → agent → `replaceAiTags` → `profile_refreshed_at = now()`. Failure logs and returns (never throws to retry loop more than default tries).
- [ ] **Step 1: Failing tests** — `refresh stores ai tags and stamps profile_refreshed_at` (`Http::fake` languages+contents, `RepositoryProfileAgent::fake([['tags'=>[['name'=>'laravel','kind'=>'framework']]]])`), `manual tag kept after refresh`, `command dispatches only stale repos` (`Queue::fake`), `newly selected repository dispatches refresh`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(repositories): ai repository profile refresh`

### Task 5: Tags on repository settings

**Files:**
- Create: `app/Http/Controllers/Settings/GIT/GitRepositoryTagController.php` (`store`, `destroy`), `GitRepositoryProfileRefreshController.php` (POST, `throttle:5,1`, dispatches job)
- Create: `app/Http/Requests/Settings/GIT/StoreGitRepositoryTagRequest.php` (`name` required|max:50|regex `/^[\pL\pN .+#-]+$/u`, `kind` in enum values)
- Modify: `routes/settings.php` (`repositories/{gitRepository}/tags`, `repositories/{gitRepository}/tags/{tag}` scoped binding, `repositories/{gitRepository}/profile/refresh`) under `repositories.manage`
- Modify: `GitRepositorySettingsController::edit` props `tags: {id,name,kind,source}[]` (non-dismissed), `profile_refreshed_at`, `tag_kinds` options; `git-repository-settings.tsx` "Profile" section: chips (AI vs manual style), add input + kind select, remove ×, Refresh button, "Last refreshed …".
- Test: `tests/Feature/Settings/GitRepositoryTagsTest.php`
- [ ] **Step 1: Failing tests** — `manager adds tag`, `removing ai tag dismisses it`, `tag from another repository returns 404`, `refresh dispatches job` (`Queue::fake`), `member forbidden`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** tests + `npm run types:check` + `npm run lint:check` → PASS.
- [ ] **Step 5: Commit** `feat(repositories): manage profile tags in settings`

---

## Piece 3 — Features

### Task 6: Feature model, scope, resolver

**Files:**
- Create: `database/migrations/2026_11_15_120000_create_task_features_table.php`, `..._120001_add_feature_to_pull_request_tasks.php`
- Create: `app/Models/GIT/TaskFeature.php`, `app/Support/Tasks/FeatureScope.php`, `app/Services/Tasks/Features/FeatureResolver.php`
- Modify: `app/Models/GIT/PullRequestTask.php` (fillable `task_feature_id`, `feature_source`; cast `feature_source` → `AttributionSource`; `feature(): BelongsTo`)
- Test: `tests/Feature/Tasks/Features/FeatureResolverTest.php`

**Interfaces:**
- `task_features`: `id` uuid, `project_id` nullable FK cascade, `git_repository_id` nullable FK cascade, `name` string 120, `slug` string 120, `description` nullable text, `source` string 20 default `ai`, `merged_into_id` nullable self FK nullOnDelete, timestamps; unique(`project_id`,`git_repository_id`,`slug`). Index `merged_into_id`.
- `pull_request_tasks`: `task_feature_id` nullable FK nullOnDelete + index; `feature_source` string 20 nullable.
- `FeatureScope` (final readonly): `static forRepository(GitRepository $r): self` (project if set else repo); props `?string $projectId`, `?string $repositoryId`; `applyTo(Builder $q): Builder` (where on `task_features`); `key(): string`.
- `TaskFeature::scopeActive()` (`merged_into_id` null); `resolveMerged(): TaskFeature` (follow chain, max 10 hops, stop on cycle).
- `FeatureResolver::resolve(FeatureScope $scope, array $feature): ?TaskFeature` where `$feature = ['existing_slug'=>?string,'new_name'=>?string,'new_description'=>?string]`: existing slug found in scope → `resolveMerged()`; else name from `new_name` (or `existing_slug` humanised if new_name empty) → `Str::slug`, lookup in scope (incl. merged → resolve), else create `source=ai`. Both empty → null. Name trimmed, max 120.
- [ ] **Step 1: Failing tests** — `existing slug resolves`, `merged feature resolves to target` (A→B→C gives C), `unknown existing_slug falls back to creating from it`, `"Auth System" / " auth-system " / "Auth system!" resolve to one feature`, `same name in different scopes are different features`, `empty input returns null`, `scope uses project when repo has one`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(tasks): task feature model and resolver`

### Task 7: Assign features during review

**Files:**
- Create: `app/Services/Tasks/Features/FeatureCandidateProvider.php`
- Modify: `app/Ai/Agents/PullRequestReviewAgent.php` (task schema `feature` object; prompt section; `buildPrompt(..., array $previousTasks = [], array $featureContext = [])`)
- Modify: `app/Jobs/GIT/ReviewPullRequest.php` (build `$featureContext`, pass to prompt)
- Modify: `app/Services/Tasks/TaskRecorder.php`
- Test: `tests/Feature/Tasks/Features/TaskFeatureAssignmentTest.php`

**Interfaces:**
- `FeatureCandidateProvider::forRepository(GitRepository $r): array{project: ?array{name:string,description:?string,repositories:array<array{name:string,tags:string[]}>}, repository_tags: string[], features: array<array{slug:string,name:string,description:?string,task_count:int}>}` — active features in scope ordered by latest task activity, cap `config('pulllens.reviews.feature_context_limit', 40)`.
- Agent schema per task: `'feature' => object{ existing_slug: string|null, new_name: string|null, new_description: string|null }` required. Prompt: "FEATURES" block (trusted context JSON) + rules — prefer existing slug copied verbatim; new only for a new product capability area; product-level names 2–4 words Title Case ("Auth system", not "Fix register"); test/docs/chore tasks join the feature they support; truly cross-cutting maintenance → `new_name: "Maintenance"`.
- `TaskRecorder::record()` (signature unchanged): normalize keeps `feature` raw; inside transaction, for each task: if existing row has `feature_source = manual` → do not overwrite `task_feature_id`/`feature_source` (exclude from `updateOrCreate` attributes); else `task_feature_id = FeatureResolver::resolve(FeatureScope::forRepository(repo), feature)?->id`, `feature_source = ai` (null when unresolved). **Carry-over:** before the delete of stale rows, for each stale row with `feature_source = manual`, pick the newly saved task in this PR (not itself manual) with the largest `files` intersection (tie/zero → highest `similar_text` title percent ≥ 50) and copy `task_feature_id` + `feature_source = manual`.
- [ ] **Step 1: Failing tests** — `review assigns tasks to existing feature by slug`, `review creates new feature`, `tasks from two repos in one project share a feature`, `manual assignment not overwritten on re-review`, `manual assignment carried to re-keyed task with overlapping files`, `prompt includes project and tags context` (assert `buildPrompt` output contains feature slug and tag), `missing feature key leaves task ungrouped`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** this file + `tests/Feature/Tasks` → PASS (existing tests untouched).
- [ ] **Step 5: Commit** `feat(tasks): assign tasks to features during review`

### Task 8: Feature corrections and scope migration

**Files:**
- Create: `app/Services/Tasks/Features/FeatureManager.php`
- Modify: `app/Services/Tasks/Features/FeatureScopeMigrator.php` (fill bodies)
- Create: `app/Http/Controllers/Tasks/TaskFeatureController.php` (`update` rename/description, `merge`), `app/Http/Controllers/Tasks/TaskFeatureAssignmentController.php` (PUT task feature)
- Create: requests `app/Http/Requests/Tasks/UpdateTaskFeatureRequest.php`, `MergeTaskFeatureRequest.php`, `AssignTaskFeatureRequest.php`
- Modify: `routes/tasks.php` (nested group `permission:repositories.manage`: `PUT tasks/features/{taskFeature}`, `POST tasks/features/{taskFeature}/merge`, `PUT tasks/{pullRequestTask}/feature`)
- Modify: `app/Services/Repositories/...` repo delete path: after repo deletion prune AI features with zero tasks (`FeatureManager::pruneEmpty()`; call from `GitRepositoryDestroyController` flow's service)
- Test: `tests/Feature/Tasks/Features/FeatureManagementTest.php`

**Interfaces:**
- `FeatureManager::rename(TaskFeature $f, string $name, ?string $description): TaskFeature` (→ `source=manual`, slug kept stable so AI references still resolve); `merge(TaskFeature $from, TaskFeature $into): void` (same scope else `ValidationException`; not self; resolves `$into` merged chain first; moves tasks keeping their `feature_source`; sets `merged_into_id`); `assignTask(PullRequestTask $t, ?string $featureId, ?string $newName): void` (feature must be in task's scope; `newName` creates `source=manual` feature; sets `feature_source=manual`); `pruneEmpty(): int` (ai, active, no tasks, no features merged into it).
- `FeatureScopeMigrator::repositoryJoined(repo, project)`: for each repo-scoped feature of repo → if project has active feature with same slug: merge into it (if repo feature is manual and project one ai, rename project one to repo one's name, `source=manual`); else set `project_id = project`, `git_repository_id = null`. `repositoryLeft(repo, oldProject)`: for each project feature that has tasks of this repo → find-or-create repo-scoped feature with same slug/name/source, move this repo's tasks to it. `ProjectService::delete` calls `repositoryLeft` for each repo before deleting.
- Request authorization: task/feature must be inside `RepositoryScope::forUser()` (404 otherwise).
- [ ] **Step 1: Failing tests** — `rename marks manual and later review keeps name`, `merge moves tasks and future slug references resolve to target`, `merge into self or other scope is 422`, `move task marks manual`, `repo joining project promotes features and merges same slug`, `manual name wins on promote`, `repo leaving project keeps feature names under repo`, `deleting project keeps tasks grouped`, `member gets 403`, `pruneEmpty removes empty ai features only`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS (+ Task 1 tests now exercising real migrator).
- [ ] **Step 5: Commit** `feat(tasks): feature corrections and project scope migration`

### Task 9: Backfill grouping (UI + command)

**Files:**
- Create: `app/Ai/Agents/FeatureGroupingAgent.php` (input: features + tasks `{id,title,type,description,repository}`; output `assignments: [{task_id, existing_slug|null, new_name|null, new_description|null}]`)
- Create: `app/Services/Tasks/Features/FeatureBackfillService.php`, `app/Jobs/Tasks/GroupTaskFeatures.php`, `app/Console/Commands/Tasks/GroupTaskFeatures.php` (`tasks:group-features {--repository=} {--project=}`)
- Create: `app/Http/Controllers/Settings/Projects/FeatureGroupingController.php` (`preview` GET JSON, `start` POST)
- Modify: `routes/settings.php`, `ProjectController::index` props, `resources/js/pages/settings/projects.tsx` (per project + "Repositories without a project" section: button "Group existing tasks" → dialog showing preview → confirm → progress bar polling `preview` every 3 s while running)
- Test: `tests/Feature/Tasks/Features/FeatureBackfillTest.php`

**Interfaces:**
- `FeatureBackfillService::preview(FeatureScope $s): array{ungrouped:int, batches:int, running:bool, processed:int}` (batch size 25; ungrouped = tasks in scope with `task_feature_id` null); `start(FeatureScope $s): void` (sets `Cache` key `feature-grouping:{scope key}` `{running:true, processed:0, total}`, dispatches job; no-op if running); `runBatch(FeatureScope $s): int` (oldest 25 ungrouped non-manual tasks → agent → `FeatureResolver::resolve` per assignment, ignore task_ids not in batch → records `AiOperation::FeatureGrouping` usage → returns count).
- Job loops `runBatch` until 0 or 200 batches, updating cache; on exception sets `running:false, error`. Unassignable tasks in a batch get skipped so loop terminates (track attempted ids in cache, exclude from next batch).
- Command resolves scope from option (repo without project or project) and runs synchronously with progress output.
- [ ] **Step 1: Failing tests** — `preview counts ungrouped tasks and batches`, `start dispatches once while running` (`Queue::fake`), `runBatch assigns tasks and ignores foreign task ids` (`FeatureGroupingAgent::fake`), `manual tasks skipped`, `loop terminates when agent returns no assignments`, `command groups a repository`, `member forbidden`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** tests + `npm run types:check` + `npm run lint:check` → PASS.
- [ ] **Step 5: Commit** `feat(tasks): backfill feature grouping for existing tasks`

### Task 10: Feature view on the tasks board

**Files:**
- Create: `app/Support/Queries/GIT/TaskFeatureQuery.php`
- Modify: `app/Http/Requests/Tasks/IndexTasksRequest.php` (`view` in `features|list` default `features`; `feature` uuid or `ungrouped`)
- Modify: `app/Support/Queries/GIT/TaskQuery.php` add `inFeature(?string $featureId): self` (`ungrouped` → whereNull)
- Modify: `app/Http/Controllers/Tasks/TaskBoardController.php` (when `view=features`: prop `features`; always pass `can_manage_features: bool`, `feature_options` for move dialog limited to visible scopes)
- Modify: `app/Support/Presenters/GIT/TaskPresenter.php` (add `feature: {id,name}|null`, `feature_source`)
- Modify: `resources/js/pages/tasks/index.tsx` — extract new `resources/js/pages/tasks/feature-table.tsx` and `resources/js/pages/tasks/feature-actions.tsx` (keep index.tsx from growing)
- Test: `tests/Feature/Tasks/Features/TaskBoardFeaturesTest.php`

**Interfaces:**
- `TaskFeatureQuery::__construct(TaskQuery $filtered)` uses filtered task subquery; `rows(int $perPage): LengthAwarePaginator` of `{id, name, scope_label, source, tasks, shipped, came_back, contributors: {login,name,avatar_url}[] (max 5 distinct by task count), last_activity_at}` — one aggregate query grouped by `task_feature_id` over filtered tasks joined to active features; plus an `Ungrouped` row (`id = 'ungrouped'`) when any filtered task has null feature, always last. Sorted by `last_activity_at` desc.
- Frontend: view toggle "Features | All tasks"; feature row expand loads tasks with `router.reload({ only: ['tasks','pagination'], data: { feature: id } })` (reuse `TaskRow`); manager actions: rename, merge (select target), move task (select feature or "New feature…").
- [ ] **Step 1: Failing tests** — `features view lists rollup counts` (Tasks/Shipped/Came back per Global Constraints, reworked task counted once), `filters narrow counts` (type=bugfix), `ungrouped row appears last`, `contributor sees only granted repos in a project feature`, `feature filter returns its tasks`, `list view still works` (existing props).
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** `tests/Feature/Tasks` + types + lint + `npm run build` → PASS.
- [ ] **Step 5: Commit** `feat(tasks): feature rollup view on tasks board`

### Task 11: Tasks report by feature

**Files:**
- Modify: `app/Services/Tasks/TaskReportService.php` (add `byFeature` section: same three counts + hours sum, top 20 by tasks), `resources/js/pages/reports/tasks.tsx` (table "By feature")
- Test: extend `tests/Feature/Reports/TaskReportTest.php` with `report includes by feature breakdown`
- [ ] Steps: failing test → run FAIL → implement → run PASS → commit `feat(reports): tasks report by feature`

### Task 12: Docs and full verification

**Files:**
- Modify: `docs/guide/tasks.html` (features, projects, tags, grouping button), `README.md` feature list line
- [ ] **Step 1:** Update docs.
- [ ] **Step 2: Run** `php artisan test` → all pass; `vendor/bin/pint --test` clean; `npm run lint:check`, `npm run types:check`, `npm run build` clean; `php artisan migrate:fresh --seed` on test DB works and `php artisan migrate:rollback --step=5` reverses new migrations.
- [ ] **Step 3: Commit** `docs: projects, repository tags and feature grouping`
