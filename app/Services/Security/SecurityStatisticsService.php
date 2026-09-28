<?php

namespace App\Services\Security;

use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\FindingStatusFilter;
use App\Enums\GIT\SecurityFindingKind;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequestReviewFinding;
use App\Support\Access\RepositoryScope;
use App\Support\Queries\GIT\SecurityFindingQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Headline numbers for the Security page, scoped only by repository.
 *
 * Like the Findings tiles, they ignore the tab, severity, status and search filters,
 * so narrowing the list does not change the totals it is compared against.
 */
class SecurityStatisticsService
{
    /**
     * The four summary tiles.
     *
     * @return array{open_secrets: int, open_critical_high_vulnerabilities: int, open_misconfigurations: int, resolved_last_7_days: int}
     */
    public function tiles(RepositoryScope $scope): array
    {
        return [
            'open_secrets' => $this->open($scope, SecurityFindingKind::Secret)->count(),
            'open_critical_high_vulnerabilities' => $this->open($scope, SecurityFindingKind::Vulnerability)
                ->whereIn('severity', [FindingSeverity::Critical->value, FindingSeverity::High->value])
                ->count(),
            'open_misconfigurations' => $this->open($scope, SecurityFindingKind::Misconfiguration)->count(),
            'resolved_last_7_days' => (new SecurityFindingQuery)->withinScope($scope)->builder()
                ->where('resolved_at', '>=', now()->subDays(7))
                ->count(),
        ];
    }

    /**
     * Open findings per tab, for the tab badges.
     *
     * @return array<string, int>
     */
    public function openCounts(RepositoryScope $scope): array
    {
        $counts = [];

        foreach (SecurityFindingKind::cases() as $kind) {
            $counts[$kind->value] = $this->open($scope, $kind)->count();
        }

        return $counts;
    }

    /**
     * Whether any visible repository has the scanner behind each tab turned on.
     *
     * @return array<string, bool>
     */
    public function scanningEnabled(RepositoryScope $scope): array
    {
        $enabled = fn (string $column): bool => $scope
            ->applyToRepositories(GitRepository::query())
            ->where($column, true)
            ->exists();

        // Trivy finds both vulnerabilities and misconfigurations behind one setting.
        $trivy = $enabled('vulnerability_scanning_enabled');

        return [
            SecurityFindingKind::Secret->value => $enabled('secret_scanning_enabled'),
            SecurityFindingKind::Vulnerability->value => $trivy,
            SecurityFindingKind::Misconfiguration->value => $trivy,
        ];
    }

    /**
     * Open scanner findings of one kind in scope.
     *
     * @return Builder<PullRequestReviewFinding>
     */
    private function open(RepositoryScope $scope, SecurityFindingKind $kind): Builder
    {
        return (new SecurityFindingQuery)
            ->withinScope($scope)
            ->ofKind($kind)
            ->withStatus(FindingStatusFilter::Open)
            ->builder();
    }
}
