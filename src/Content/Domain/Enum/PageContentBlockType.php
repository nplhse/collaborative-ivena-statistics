<?php

declare(strict_types=1);

namespace App\Content\Domain\Enum;

enum PageContentBlockType: string
{
    case Richtext = 'richtext';
    case Image = 'image';
    case Cta = 'cta';
    case Quote = 'quote';
    case Headline = 'headline';
    case Highlight = 'highlight';
    case Accordion = 'accordion';

    public function translationKey(): string
    {
        return 'label.block_type.'.$this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return array<string, string>
     */
    public static function formChoices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->translationKey()] = $case->value;
        }

        return $choices;
    }

    public static function tryFromString(?string $value): ?self
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        return self::tryFrom($value);
    }
}
