<?php

use App\Enums\GIT\AttributionSource;
use App\Enums\GIT\RepositoryTagKind;
use App\Models\GIT\GitRepository;
use App\Services\Repositories\RepositoryTagService;

beforeEach(function () {
    $this->service = app(RepositoryTagService::class);
    $this->repo = GitRepository::factory()->create();
});

it('keeps manual tags when ai tags are replaced', function () {
    $this->service->addManual($this->repo, 'billing', RepositoryTagKind::Domain);
    $this->service->replaceAiTags($this->repo, [['name' => 'laravel', 'kind' => 'framework']]);
    $this->service->replaceAiTags($this->repo, [['name' => 'php', 'kind' => 'language']]);

    expect($this->service->forPrompt($this->repo))->toBe(['billing', 'php']);
});

it('does not re-add a dismissed ai tag', function () {
    $this->service->replaceAiTags($this->repo, [['name' => 'laravel', 'kind' => 'framework']]);
    $this->service->remove($this->repo->tags()->where('name', 'laravel')->first());
    $this->service->replaceAiTags($this->repo, [['name' => 'laravel', 'kind' => 'framework'], ['name' => 'php', 'kind' => 'language']]);

    $tag = $this->repo->tags()->where('name', 'laravel')->first();
    expect($tag->dismissed)->toBeTrue()
        ->and($this->repo->tags()->count())->toBe(2)
        ->and($this->service->forPrompt($this->repo))->toBe(['php']);
});

it('slugifies and dedupes names', function () {
    $this->service->addManual($this->repo, 'Laravel ', RepositoryTagKind::Framework);
    $this->service->addManual($this->repo, 'laravel', RepositoryTagKind::Framework);
    $this->service->replaceAiTags($this->repo, [['name' => 'LARAVEL', 'kind' => 'framework'], ['name' => 'Node.js', 'kind' => 'bogus']]);

    expect($this->repo->tags()->count())->toBe(2)
        ->and($this->repo->tags()->where('name', 'laravel')->first()->source)->toBe(AttributionSource::Manual)
        ->and($this->repo->tags()->where('name', 'nodejs')->first()->kind)->toBe(RepositoryTagKind::Other);
});

it('turns a dismissed ai tag into a manual one when added by hand', function () {
    $this->service->replaceAiTags($this->repo, [['name' => 'vue', 'kind' => 'framework']]);
    $this->service->remove($this->repo->tags()->first());
    $tag = $this->service->addManual($this->repo, 'vue', RepositoryTagKind::Framework);

    expect($tag->source)->toBe(AttributionSource::Manual)->and($tag->dismissed)->toBeFalse();
});

it('deletes a removed manual tag', function () {
    $tag = $this->service->addManual($this->repo, 'billing', RepositoryTagKind::Domain);
    $this->service->remove($tag);

    expect($this->repo->tags()->count())->toBe(0);
});

it('excludes dismissed tags from the prompt', function () {
    $this->service->replaceAiTags($this->repo, [['name' => 'a', 'kind' => 'other'], ['name' => 'b', 'kind' => 'other']]);
    $this->service->remove($this->repo->tags()->where('name', 'a')->first());

    expect($this->service->forPrompt($this->repo))->toBe(['b']);
});

it('caps ai tags at 20', function () {
    $tags = array_map(fn ($i) => ['name' => "tag-$i", 'kind' => 'other'], range(1, 30));
    $this->service->replaceAiTags($this->repo, $tags);

    expect($this->repo->tags()->count())->toBe(20);
});
