<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFilterRequest;
use App\Services\Reports\RepositoryReportService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-repository activity and review quality comparison.
 */
class RepositoriesReportController extends Controller
{
    public function __construct(private readonly RepositoryReportService $repositories) {}

    public function __invoke(ReportFilterRequest $request): Response
    {
        return Inertia::render('reports/repositories', [
            'repositories' => $this->repositories->handle(),
        ]);
    }
}
