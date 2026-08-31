<?php

namespace App\Enums\GIT;

enum CommentReplyType: string implements \JsonSerializable
{
    case Clarification = 'clarification';
    case FixConfirmed = 'fix_confirmed';
    case FixRejected = 'fix_rejected';
    case QuestionAnswered = 'question_answered';
    case Acknowledged = 'acknowledged';
    case OutOfScope = 'out_of_scope';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Clarification => 'Clarification',
            self::FixConfirmed => 'Fix confirmed',
            self::FixRejected => 'Fix rejected',
            self::QuestionAnswered => 'Question answered',
            self::Acknowledged => 'Acknowledged',
            self::OutOfScope => 'Out of scope',
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
     *      *
     *      * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
