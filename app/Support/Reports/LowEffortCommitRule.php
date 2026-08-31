<?php

namespace App\Support\Reports;

/**
 * The single definition of what counts as a "low-effort" commit message.
 *
 * The rule was previously expressed twice — once as a PHP regex and once inlined
 * into raw SQL — which meant the commit-quality report and the daily effort report
 * could disagree about the same commit. Both now derive from the word list here.
 */
final class LowEffortCommitRule
{
    /** Messages this short are treated as low effort regardless of content. */
    public const MIN_MEANINGFUL_LENGTH = 4;

    /**
     * Messages consisting of nothing but one of these words.
     *
     * @var array<int, string>
     */
    private const PLACEHOLDER_WORDS = [
        'wip', 'fix', 'test', 'temp', 'dev', 'tmp', 'ok', 'patch', 'update', 'changes',
        'misc', 'asdf', 'asd', 'pr', 'bump', 'commit', 'merge', 'done', 'work',
        'initial', 'init', 'lol', 'heh', 'yo', 'hey', 'test commit', 'minor',
        'hotfix', 'quickfix', 'quick fix', 'revert', 'reverted',
    ];

    /**
     * Whether a commit message carries no useful information.
     */
    public static function matches(string $message): bool
    {
        $message = trim($message);

        if (mb_strlen($message) <= self::MIN_MEANINGFUL_LENGTH) {
            return true;
        }

        return (bool) preg_match('/^('.self::alternation().')$/i', $message);
    }

    /**
     * SQL expression evaluating to 1 for a low-effort message and 0 otherwise.
     *
     * Kept here so the aggregate queries and the PHP check stay in lockstep. The
     * output contains only the literal word list defined above — no user input — so
     * it is safe to interpolate into a raw expression.
     */
    public static function sqlCaseExpression(string $column): string
    {
        return sprintf(
            "CASE WHEN LENGTH(TRIM(%s)) <= %d OR LOWER(TRIM(%s)) ~* '^(%s)$' THEN 1 ELSE 0 END",
            $column,
            self::MIN_MEANINGFUL_LENGTH,
            $column,
            self::alternation(),
        );
    }

    /**
     * The word list as a regex alternation.
     */
    private static function alternation(): string
    {
        return implode('|', array_map(
            static fn (string $word): string => preg_quote($word, '/'),
            self::PLACEHOLDER_WORDS,
        ));
    }
}
