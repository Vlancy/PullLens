<?php

namespace App\Enums\GIT;

enum CommentReplyType: string implements \JsonSerializable
{
    case Clarification    = 'clarification';
    case FixConfirmed     = 'fix_confirmed';
    case FixRejected      = 'fix_rejected';
    case QuestionAnswered = 'question_answered';
    case Acknowledged     = 'acknowledged';
    case OutOfScope       = 'out_of_scope';

    public function label(): string
    {
        return match ($this) {
            self::Clarification    => 'Clarification',
            self::FixConfirmed     => 'Fix confirmed',
            self::FixRejected      => 'Fix rejected',
            self::QuestionAnswered => 'Question answered',
            self::Acknowledged     => 'Acknowledged',
            self::OutOfScope       => 'Out of scope',
        };
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
