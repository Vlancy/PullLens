<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GIT\FindingResolutionType;
use App\Http\Controllers\Controller;
use App\Models\GIT\PullRequestReviewFinding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FindingResolveController extends Controller
{
    public function __invoke(Request $request, PullRequestReviewFinding $finding): RedirectResponse
    {
        $request->validate([
            'resolution_type' => ['required', 'in:'.implode(',', FindingResolutionType::values())],
        ]);

        $finding->update([
            'resolved_at' => now(),
            'resolution_type' => $request->input('resolution_type'),
        ]);

        return back();
    }
}
