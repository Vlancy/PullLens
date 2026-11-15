<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Enums\GIT\RepositoryTagKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\GIT\StoreGitRepositoryTagRequest;
use App\Models\GIT\GitRepository;
use App\Models\GIT\GitRepositoryTag;
use App\Services\Repositories\RepositoryTagService;
use Illuminate\Http\RedirectResponse;

class GitRepositoryTagController extends Controller
{
    /**
     * Add a manual profile tag to a repository.
     */
    public function store(
        StoreGitRepositoryTagRequest $request,
        GitRepository $gitRepository,
        RepositoryTagService $tags,
    ): RedirectResponse {
        $tags->addManual(
            $gitRepository,
            $request->validated('name'),
            RepositoryTagKind::from($request->validated('kind')),
        );

        return back();
    }

    /**
     * Remove a profile tag (manual tags are deleted, AI tags are dismissed).
     */
    public function destroy(GitRepository $gitRepository, GitRepositoryTag $tag, RepositoryTagService $tags): RedirectResponse
    {
        $tags->remove($tag);

        return back();
    }
}
