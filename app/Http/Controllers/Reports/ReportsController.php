<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    /** Render the system-wide overview report page. */
    public function overview(): Response
    {
        return Inertia::render('reports/overview', [
            'stats' => $this->reports->overview(),
        ]);
    }

    /** Render the per-developer metrics report page. */
    public function developers(Request $request): Response
    {
        $period = $request->get('period', 'all');

        return Inertia::render('reports/developers', [
            'developers' => $this->reports->developers($period),
            'period' => $period,
        ]);
    }

    /** Render the per-repository statistics report page. */
    public function repositories(): Response
    {
        return Inertia::render('reports/repositories', [
            'repositories' => $this->reports->repositories(),
        ]);
    }

    /** Render the commit quality report page. */
    public function commits(Request $request): Response
    {
        $period = $request->get('period', 'all');

        return Inertia::render('reports/commits', [
            'commits' => $this->reports->commits($period),
            'period' => $period,
        ]);
    }

    /** Render the daily performance breakdown report page. */
    public function daily(Request $request): Response
    {
        $period = $request->get('period', '30d');

        return Inertia::render('reports/daily', [
            'days' => $this->reports->daily($period),
            'period' => $period,
        ]);
    }
}
