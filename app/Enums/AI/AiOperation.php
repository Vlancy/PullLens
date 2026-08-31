<?php

namespace App\Enums\AI;

/**
 * The kinds of work PullLens spends AI credit on.
 *
 * Recording the operation alongside every call is what makes the usage report
 * actionable: "we spent $80 last month" is not useful, "$68 of it was pull request
 * reviews and $9 was re-reviewing commits we had already reviewed" is.
 */
enum AiOperation: string implements \JsonSerializable
{
    case PullRequestReview = 'pull_request_review';
    case FindingDispute = 'finding_dispute';
    case CommentReply = 'comment_reply';
    case AssistantChat = 'assistant_chat';
    case ConnectionTest = 'connection_test';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::PullRequestReview => 'PR review',
            self::FindingDispute => 'Finding dispute',
            self::CommentReply => 'Comment reply',
            self::AssistantChat => 'Assistant chat',
            self::ConnectionTest => 'Connection test',
        };
    }

    /**
     * Whether this operation is triggered automatically rather than by a person.
     *
     * Automatic spend is the part that grows without anyone deciding to grow it, so
     * the report separates the two.
     */
    public function isAutomatic(): bool
    {
        return match ($this) {
            self::PullRequestReview, self::FindingDispute, self::CommentReply => true,
            self::AssistantChat, self::ConnectionTest => false,
        };
    }

    /**
     * Serialize as the backing string value, so the enum crosses the wire
     * as a plain scalar rather than an object the front end must unwrap.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * All backing values, for validation rules and "in" comparisons.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Value and label pairs for populating a select control.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $operation): array => ['value' => $operation->value, 'label' => $operation->label()],
            self::cases(),
        );
    }
}
