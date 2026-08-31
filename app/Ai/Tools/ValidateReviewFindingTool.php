<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ValidateReviewFindingTool implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Checks whether a proposed review finding follows PullLens safety and quality rules before it is returned.';
    }

    /**
     * Validate a proposed finding against review policy heuristics.
     */
    public function handle(Request $request): Stringable|string
    {
        $title = trim((string) $request->string('title'));
        $explanation = trim((string) $request->string('explanation'));
        $suggestedFix = trim((string) $request->string('suggested_fix'));
        $severity = (string) $request->string('severity');
        $file = trim((string) $request->string('file'));
        $dedupeKey = trim((string) $request->string('dedupe_key'));

        $problems = [];

        if ($title === '' || $explanation === '' || $suggestedFix === '') {
            $problems[] = 'Finding must include a title, explanation, and suggested fix.';
        }

        if ($file === '') {
            $problems[] = 'Finding must reference a file from the PR content.';
        }

        if ($dedupeKey === '') {
            $problems[] = 'Finding must include a stable dedupe_key for database storage and comment deduplication.';
        }

        if (! in_array($severity, ['critical', 'high', 'medium', 'low', 'informational'], true)) {
            $problems[] = 'Severity must use the PullLens severity enum.';
        }

        $combined = strtolower($title.' '.$explanation.' '.$suggestedFix);

        foreach (['ignore previous instructions', 'system prompt', 'developer message', 'jailbreak'] as $needle) {
            if (str_contains($combined, $needle)) {
                $problems[] = 'Finding appears to discuss prompt mechanics instead of code behavior.';
                break;
            }
        }

        foreach (['exploit payload', 'steal token', 'exfiltrate', 'reverse shell'] as $needle) {
            if (str_contains($combined, $needle)) {
                $problems[] = 'Finding includes unsafe offensive detail; replace with defensive remediation.';
                break;
            }
        }

        return json_encode([
            'valid' => $problems === [],
            'problems' => $problems,
            'guidance' => $problems === []
                ? 'Finding passes policy heuristics. Return it only if it is supported by the PR content.'
                : 'Revise or omit the finding before returning the final structured review.',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'severity' => $schema->string()
                ->enum(['critical', 'high', 'medium', 'low', 'informational'])
                ->required(),
            'dedupe_key' => $schema->string()->required(),
            'file' => $schema->string()->required(),
            'explanation' => $schema->string()->required(),
            'suggested_fix' => $schema->string()->required(),
        ];
    }
}
