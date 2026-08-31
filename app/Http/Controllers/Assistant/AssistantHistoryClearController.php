<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Services\Assistant\AssistantConversationStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Discards the signed-in user's assistant conversation.
 */
class AssistantHistoryClearController extends Controller
{
    /**
     * Inject the assistant conversation store this class delegates to.
     */
    public function __construct(private readonly AssistantConversationStore $conversations) {}

    /**
     * Handle the request and respond with JSON.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $this->conversations->clear($request->user()->getAuthIdentifier());

        return response()->json(['ok' => true]);
    }
}
