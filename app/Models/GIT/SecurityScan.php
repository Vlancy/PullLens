<?php

namespace App\Models\GIT;

use App\Enums\GIT\Scanner;
use App\Enums\GIT\SecurityScanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'pull_request_id',
    'git_repository_id',
    'scanner',
    'head_sha',
    'status',
    'findings_count',
    'files_scanned',
    'files_skipped',
    'check_run_id',
    'notes_commit_sha',
    'scanner_version',
    'duration_ms',
    'error',
])]
class SecurityScan extends Model
{
    use HasUuids;

    /**
     * Attribute casts for this model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scanner' => Scanner::class,
            'status' => SecurityScanStatus::class,
            'findings_count' => 'integer',
            'files_scanned' => 'integer',
            'files_skipped' => 'integer',
            'check_run_id' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * The pull request this scan ran against.
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    /**
     * The repository this scan ran against.
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class, 'git_repository_id');
    }

    /**
     * The findings this scan produced.
     */
    public function findings(): HasMany
    {
        return $this->hasMany(PullRequestReviewFinding::class, 'security_scan_id');
    }
}
