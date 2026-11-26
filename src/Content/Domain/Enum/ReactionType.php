<?php

declare(strict_types=1);

namespace App\Content\Domain\Enum;

/**
 * Reactions a user can give to supported content (#467). The order of the cases is the order of the picker and of
 * the counts. There is deliberately no negative reaction: reactions are feedback, not a vote.
 */
enum ReactionType: string
{
    case Like = 'like';
    case Love = 'love';
    case Hooray = 'hooray';
    case Rocket = 'rocket';
    case Insightful = 'insightful';
    case Eyes = 'eyes';
    case Funny = 'funny';
    case Thinking = 'thinking';

    public function emoji(): string
    {
        return match ($this) {
            self::Like => '👍',
            self::Love => '❤️',
            self::Hooray => '🎉',
            self::Rocket => '🚀',
            self::Insightful => '💡',
            self::Eyes => '👀',
            self::Funny => '😄',
            self::Thinking => '🤔',
        };
    }

    /** Translation key in the content domain. */
    public function labelKey(): string
    {
        return 'reaction.type.'.$this->value;
    }
}
