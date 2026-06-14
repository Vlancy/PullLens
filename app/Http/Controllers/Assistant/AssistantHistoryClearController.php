<?php

namespace App\Http\Controllers\Assistant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AssistantHistoryClearController
{
    public function __invoke(Request $request): JsonResponse
    {
        Cache::forget("assistant_history:{$request->user()->id}");

        return response()->json(['ok' => true]);
    }
}
