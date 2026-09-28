<?php

namespace App\Services\Git\SecretScanning;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Runs the gitleaks CLI over a directory and returns its redacted findings.
 *
 * The process runs from inside the directory and scans ".", so every reported path
 * is relative - which is also the form .gitleaksignore fingerprints use.
 */
class GitleaksRunner
{
    /** Longest match text kept, so one minified line cannot flood a comment. */
    private const MAX_MATCH_LENGTH = 200;

    private ?string $version = null;

    /**
     * Whether the configured binary can be executed.
     */
    public function isAvailable(): bool
    {
        return $this->version() !== null;
    }

    /**
     * The gitleaks version string, or null when the binary cannot run.
     */
    public function version(): ?string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        try {
            $result = Process::timeout(10)->run([$this->binary(), 'version']);
        } catch (Throwable) {
            return null;
        }

        $version = trim($result->output());

        if (! $result->successful() || $version === '') {
            return null;
        }

        return $this->version = mb_substr($version, 0, 40);
    }

    /**
     * Scan a directory and return the redacted hits.
     *
     * @return list<GitleaksHit>
     *
     * @throws GitleaksFailed
     */
    public function run(string $directory, ?string $configPath = null, ?string $ignorePath = null): array
    {
        $reportPath = rtrim($directory, '/').'.report.json';

        $command = [
            $this->binary(), 'dir', '.',
            '--redact',
            '--no-banner',
            '--no-color',
            '--log-level', 'error',
            '--exit-code', '0',
            '--report-format', 'json',
            '--report-path', $reportPath,
        ];

        if ($configPath !== null) {
            array_push($command, '--config', $configPath);
        }

        if ($ignorePath !== null) {
            array_push($command, '--gitleaks-ignore-path', $ignorePath);
        }

        try {
            try {
                $result = Process::path($directory)->timeout($this->timeout())->run($command);
            } catch (ProcessTimedOutException $e) {
                throw new GitleaksFailed("gitleaks timed out after {$this->timeout()} seconds", previous: $e);
            }

            if (! $result->successful()) {
                throw new GitleaksFailed(sprintf(
                    'gitleaks exited with code %d: %s',
                    $result->exitCode(),
                    mb_substr(trim($result->errorOutput()), 0, 500),
                ));
            }

            if (! is_file($reportPath)) {
                throw new GitleaksFailed('gitleaks wrote no report');
            }

            return $this->parse((string) file_get_contents($reportPath));
        } finally {
            @unlink($reportPath);
        }
    }

    /**
     * Turn the JSON report into hits.
     *
     * @return list<GitleaksHit>
     */
    private function parse(string $report): array
    {
        $decoded = json_decode($report, true);

        if (! is_array($decoded)) {
            throw new GitleaksFailed('gitleaks report is not valid JSON');
        }

        $hits = [];

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $hits[] = new GitleaksHit(
                ruleId: (string) ($row['RuleID'] ?? ''),
                description: (string) ($row['Description'] ?? ''),
                file: ltrim((string) preg_replace('#^\./#', '', (string) ($row['File'] ?? '')), '/'),
                line: (int) ($row['StartLine'] ?? 0),
                match: mb_substr((string) ($row['Match'] ?? ''), 0, self::MAX_MATCH_LENGTH),
            );
        }

        return $hits;
    }

    /**
     * The configured binary path.
     */
    private function binary(): string
    {
        return (string) config('pulllens.secret_scanning.binary', 'gitleaks');
    }

    /**
     * The configured scan timeout in seconds.
     */
    private function timeout(): int
    {
        return max(5, (int) config('pulllens.secret_scanning.timeout', 60));
    }
}
