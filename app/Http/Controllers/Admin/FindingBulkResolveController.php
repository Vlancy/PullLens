<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GIT\FindingResolutionType;
use App\Http\Controllers\Controller;
use App\Models\GIT\PullRequestReviewFinding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FindingBulkResolveController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'finding_ids'     => ['required', 'array', 'min:1'],
            'finding_ids.*'   => ['required', 'uuid'],
            'resolution_type' => ['required', 'in:'.implode(',', FindingResolutionType::values())],
        ]);

        PullRequestReviewFinding::whereIn('id', $request->input('finding_ids'))
            ->whereNull('resolved_at')
            ->update([
                'resolved_at'     => now(),
                'resolution_type' => $request->input('resolution_type'),
            ]);

        return back();
    }
}
