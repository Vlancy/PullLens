<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Http\Controllers\Controller;
use App\Models\GIT\GitProviderApp;
use App\Services\Git\GitHubApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class GitHubAppTestController extends Controller
{
    public function __invoke(Request $request, GitHubApiClient $api): JsonResponse
    {
        $request->validate([
            'app_id' => ['required', 'string'],
            'private_key' => ['required', 'string'],
        ]);

        // Build an unsaved model so the encrypted cast works the same way as a
        // persisted one — getApp() reads private_key via the cast getter.
        $app = new GitProviderApp;
        $app->app_id = $request->input('app_id');
        $app->private_key = $request->input('private_key');

        try {
            $data = $api->getApp($app);

            return response()->json([
                'success' => true,
                'message' => (string) data_get($data, 'name', 'App connected'),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'GitHub returned an error — check your App ID and private key. ('.$e->getMessage().')',
            ]);
        }
    }
}
