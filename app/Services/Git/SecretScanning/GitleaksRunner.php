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
 *
 * gitleaks 8.28 reads config from the scanned directory unless told otherwise:
 * without --config it loads ./.gitleaks.toml (and any ./.gitleaks.* viper finds),
 * and it loads ./.gitleaksignore even when --gitleaks-ignore-path is given. So a
 * config and an ignore file are always required, and must live outside the
 * directory; the caller keeps the directory itself free of files with those names.
 */
class GitleaksRunner
{
    /** Longest match text kept, so one minified line cannot flood a comment. */
    private const MAX_MATCH_LENGTH = 200;

    /**
     * Upper bound on one gitleaks run, below ScanPullRequestSecrets::$timeout (180),
     * so a mis-set GITLEAKS_TIMEOUT cannot keep the process alive past the job.
     */
    private const MAX_TIMEOUT = 150;

    private const MIN_TIMEOUT = 5;

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
     * Scan a directory with the given config and ignore file and return the redacted hits.
     *
     * @return list<GitleaksHit>
     *
     * @throws GitleaksFailed
     */
    public function run(string $directory, string $configPath, string $ignorePath): array
    {
        foreach ([$configPath, $ignorePath] as $path) {
            if (str_starts_with($path, rtrim($directory, '/').'/')) {
                throw new GitleaksFailed('gitleaks config and ignore files must not be inside the scanned directory');
            }
        }

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
            '--config', $configPath,
            '--gitleaks-ignore-path', $ignorePath,
        ];

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
     * The configured scan timeout in seconds, clamped to what the job can wait for.
     */
    private function timeout(): int
    {
        return min(self::MAX_TIMEOUT, max(self::MIN_TIMEOUT, (int) config('pulllens.secret_scanning.timeout', 60)));
    }
}
