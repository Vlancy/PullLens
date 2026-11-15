<?php

namespace App\Jobs\GIT;

use App\Ai\Agents\RepositoryProfileAgent;
use App\Enums\AI\AiOperation;
use App\Models\GIT\GitRepository;
use App\Services\AI\AiProviderConfigResolver;
use App\Services\AI\AiUsageRecorder;
use App\Services\Git\GitHubApiClient;
use App\Services\Git\GitHubCallerResolver;
use App\Services\Repositories\RepositoryTagService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the AI to describe a repository (languages, frameworks, domain) as tags.
 *
 * The tags later give the reviewer context about what it is looking at.
 */
class RefreshRepositoryProfile implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Files read for context, in priority order. */
    private const FILES = ['composer.json', 'package.json', 'go.mod', 'pyproject.toml', 'Gemfile', 'Cargo.toml', 'pom.xml', 'README.md'];

    /** Most files sent to the model. */
    private const MAX_FILES = 6;

    /** Characters kept from each file. */
    private const MAX_FILE_CHARS = 4000;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * Create the job for one repository.
     */
    public function __construct(public readonly string $repositoryId) {}

    /**
     * Unique key prevents duplicate refreshes for the same repository from queuing.
     */
    public function uniqueId(): string
    {
        return $this->repositoryId;
    }

    /**
     * Profile the repository and replace its AI tags.
     */
    public function handle(
        GitHubApiClient $api,
        GitHubCallerResolver $callers,
        AiProviderConfigResolver $configResolver,
        AiUsageRecorder $usageRecorder,
        RepositoryTagService $tags,
    ): void {
        $repository = GitRepository::with('account')->find($this->repositoryId);

        if ($repository === null) {
            return;
        }

        try {
            $resolved = $configResolver->forRepository($repository);
        } catch (Throwable $e) {
            Log::info('repository_profile.no_ai_provider', [
                'repository_id' => $repository->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            [$owner, $name] = explode('/', $repository->full_name, 2);
            $auth = $callers->for($repository);

            if ($auth === null) {
                return;
            }

            $languages = $api->languages($auth, $owner, $name);
            $files = [];

            foreach (self::FILES as $path) {
                if (count($files) >= self::MAX_FILES) {
                    break;
                }

                $content = $api->fetchFileContent($auth, $owner, $name, $path);

                if ($content !== null && $content !== '') {
                    $files[$path] = mb_substr($content, 0, self::MAX_FILE_CHARS);
                }
            }

            $agent = new RepositoryProfileAgent;
            $startedAt = microtime(true);

            $result = $agent->prompt(
                $agent->buildPrompt($repository->full_name, $languages, $files),
                provider: $resolved->configName,
                model: $resolved->model,
            );

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            $usageRecorder->record(
                AiOperation::RepositoryProfile,
                $resolved,
                $result->usage,
                $durationMs,
                ['git_repository_id' => $repository->id],
            );

            $tags->replaceAiTags($repository, (array) data_get($result, 'tags', []));
            $repository->update(['profile_refreshed_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('repository_profile.failed', [
                'repository_id' => $repository->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
