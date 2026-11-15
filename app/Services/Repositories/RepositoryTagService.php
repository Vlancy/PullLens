<?php

namespace App\Services\Repositories;

use App\Enums\GIT\AttributionSource;
use App\Enums\GIT\RepositoryTagKind;
use App\Models\GIT\GitRepository;
use App\Models\GIT\GitRepositoryTag;
use Illuminate\Support\Str;

class RepositoryTagService
{
    /** Most AI tags kept per repository. */
    private const MAX_AI_TAGS = 20;

    /**
     * Add a tag by hand, or turn an existing (possibly dismissed) tag into a manual one.
     *
     * @param  GitRepository  $repository  The repository to tag.
     * @param  string  $name  Free-text tag name; stored as a lowercase slug.
     * @param  RepositoryTagKind  $kind  What the tag describes.
     * @return GitRepositoryTag The saved tag.
     */
    public function addManual(GitRepository $repository, string $name, RepositoryTagKind $kind): GitRepositoryTag
    {
        return $repository->tags()->updateOrCreate(
            ['name' => $this->normalise($name)],
            ['kind' => $kind, 'source' => AttributionSource::Manual, 'dismissed' => false],
        );
    }

    /**
     * Remove a tag: manual tags are deleted, AI tags are dismissed so they stay gone.
     *
     * @param  GitRepositoryTag  $tag  The tag to remove.
     */
    public function remove(GitRepositoryTag $tag): void
    {
        if ($tag->source === AttributionSource::Manual) {
            $tag->delete();

            return;
        }

        $tag->update(['dismissed' => true]);
    }

    /**
     * Replace the repository's live AI tags with a fresh list.
     *
     * Manual and dismissed tags are never touched, and a name that already exists in
     * either state is not re-added.
     *
     * @param  GitRepository  $repository  The repository being profiled.
     * @param  array<int, array{name: string, kind: string}>  $tags  The AI's proposed tags.
     */
    public function replaceAiTags(GitRepository $repository, array $tags): void
    {
        $wanted = [];
        foreach ($tags as $tag) {
            $name = $this->normalise((string) ($tag['name'] ?? ''));
            if ($name === '' || isset($wanted[$name])) {
                continue;
            }
            $wanted[$name] = RepositoryTagKind::tryFrom((string) ($tag['kind'] ?? '')) ?? RepositoryTagKind::Other;
            if (count($wanted) >= self::MAX_AI_TAGS) {
                break;
            }
        }

        $repository->tags()
            ->where('source', AttributionSource::Ai)
            ->where('dismissed', false)
            ->whereNotIn('name', array_keys($wanted))
            ->delete();

        $existing = $repository->tags()->pluck('name')->all();

        foreach ($wanted as $name => $kind) {
            if (in_array($name, $existing, true)) {
                $repository->tags()
                    ->where('name', $name)
                    ->where('source', AttributionSource::Ai)
                    ->where('dismissed', false)
                    ->update(['kind' => $kind]);

                continue;
            }

            $repository->tags()->create(['name' => $name, 'kind' => $kind, 'source' => AttributionSource::Ai]);
        }
    }

    /**
     * Names of the live (not dismissed) tags, for the review prompt.
     *
     * @param  GitRepository  $repository  The repository to read.
     * @return array<int, string>
     */
    public function forPrompt(GitRepository $repository): array
    {
        return $repository->tags()->where('dismissed', false)->orderBy('name')->pluck('name')->all();
    }

    /**
     * Normalise a tag name to a lowercase slug capped at the column length.
     */
    private function normalise(string $name): string
    {
        return Str::limit(Str::slug($name), 50, '');
    }
}
