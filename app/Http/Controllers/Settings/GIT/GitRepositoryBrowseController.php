<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Enums\GIT\GitProvider;
use App\Http\Controllers\Controller;
use App\Models\GIT\GitAccount;
use App\Repositories\Contracts\GIT\GitAccountRepositoryInterface;
use App\Services\Git\AvailableRepositoryBrowser;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitRepositoryBrowseController extends Controller
{
    /**
     * Return the installations and repositories an operator account can select.
     */
    public function __invoke(
        Request $request,
        string $provider,
        AvailableRepositoryBrowser $browser,
        GitAccountRepositoryInterface $gitAccounts,
    ): JsonResponse {
        $gitProvider = GitProvider::tryFrom($provider) ?? abort(404);

        $account = $gitAccounts->find($request->integer('account_id'));

        abort_unless(
            $account instanceof GitAccount && $account->provider === $gitProvider,
            404,
        );

        try {
            return response()->json([
                'installations' => $browser->browse($account),
            ]);
        } catch (RequestException $exception) {
            // Surface a clean message instead of leaking the upstream GitHub error body.
            return response()->json([
                'message' => 'Could not load repositories from GitHub. Reconnect the account and try again.',
            ], 502);
        }
    }
}
