<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ValidateCommentReplyTool implements Tool
{
    private const OUT_OF_SCOPE_TYPES = ['out_of_scope'];

    private const REQUIRES_FINDING_KEY_TYPES = ['fix_confirmed', 'fix_rejected', 'clarification'];

    private const PROMPT_INJECTION_NEEDLES = [
        'ignore previous instructions',
        'ignore your instructions',
        'system prompt',
        'developer message',
        'jailbreak',
        'override rules',
        'disregard the above',
    ];

    private const OFFENSIVE_NEEDLES = [
        'exploit payload',
        'reverse shell',
        'exfiltrate',
        'steal token',
        'steal credentials',
        'sql injection',
        'command injection',
        'xss payload',
    ];

    private const INTERNAL_LEAK_NEEDLES = [
        'api key',
        'bearer token',
        'secret key',
        'openai',
        'anthropic',
        'claude',
        'gpt-',
        'ai.providers',
        'pull_lens_test_',
    ];

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Validates a proposed comment reply against PullLens safety and scope rules before it is posted. Call this before returning your final structured output.';
    }

    /**
     * Check a proposed reply for scope violations, leaked internals, and unsafe content.
     */
    public function handle(Request $request): Stringable|string
    {
        $reply = trim((string) $request->string('reply'));
        $replyType = trim((string) $request->string('reply_type'));
        $addressedFindingKey = trim((string) $request->string('addressed_finding_key', ''));
        $problems = [];

        if ($reply === '') {
            $problems[] = 'Reply text must not be empty.';
        }

        $validTypes = ['clarification', 'fix_confirmed', 'fix_rejected', 'question_answered', 'acknowledged', 'out_of_scope'];
        if (! in_array($replyType, $validTypes, true)) {
            $problems[] = 'reply_type must use the PullLens comment reply enum.';
        }

        if (in_array($replyType, self::REQUIRES_FINDING_KEY_TYPES, true) && $addressedFindingKey === '') {
            $problems[] = "reply_type '{$replyType}' must reference a specific finding via addressed_finding_key.";
        }

        $lower = strtolower($reply);

        foreach (self::PROMPT_INJECTION_NEEDLES as $needle) {
            if (str_contains($lower, $needle)) {
                $problems[] = 'Reply appears to discuss or reference prompt mechanics instead of review findings.';
                break;
            }
        }

        foreach (self::OFFENSIVE_NEEDLES as $needle) {
            if (str_contains($lower, $needle)) {
                $problems[] = 'Reply includes potentially offensive or unsafe technical detail. Use defensive remediation language only.';
                break;
            }
        }

        foreach (self::INTERNAL_LEAK_NEEDLES as $needle) {
            if (str_contains($lower, $needle)) {
                $problems[] = 'Reply may be leaking internal system details (provider names, keys, or configuration). Remove all internal references.';
                break;
            }
        }

        if (! in_array($replyType, self::OUT_OF_SCOPE_TYPES, true) && str_word_count($reply) > 200) {
            $problems[] = 'Reply exceeds the recommended length. Keep in-scope replies under 200 words.';
        }

        return json_encode([
            'valid' => $problems === [],
            'problems' => $problems,
            'guidance' => $problems === []
                ? 'Reply passes scope and safety checks. Return it as your final structured output.'
                : 'Revise the reply to address the listed problems before returning your final output.',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reply' => $schema->string()
                ->description('The full Markdown reply text to validate.')
                ->required(),
            'reply_type' => $schema->string()
                ->enum(['clarification', 'fix_confirmed', 'fix_rejected', 'question_answered', 'acknowledged', 'out_of_scope'])
                ->description('The reply type as you intend to set it in the structured output.')
                ->required(),
            'addressed_finding_key' => $schema->string()
                ->description('The dedupe_key of the finding being addressed, if applicable.')
                ->nullable(),
        ];
    }
}
