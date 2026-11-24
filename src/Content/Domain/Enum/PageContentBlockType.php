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
    /** Search field of the help (#596); no fields, the search is rendered when the page is shown. */
    case HelpSearch = 'help_search';
    /** Main topics of the help with their articles (#596), taken from the help tree when the page is shown. */
    case HelpTopics = 'help_topics';
    /** One to four cards with icon, title, text and link (#1578). */
    case Cards = 'cards';
    /** Cards for the visible subpages of the page (#1578), taken from the page tree when the page is shown. */
    case ChildPages = 'child_pages';

    public function translationKey(): string
    {
        return 'label.block_type.'.$this->value;
    }

    /**
     * Data fields that hold content the editor would lose on a type change. Presentation settings
     * (level, alignment, spacing, size, variant, …) fall back to defaults and are not listed.
     *
     * @return list<string>
     */
    public function contentFields(): array
    {
        return match ($this) {
            self::Richtext => ['html'],
            self::Image => ['mediaId', 'src', 'alt', 'caption'],
            self::Cta => ['headline', 'buttonLabel', 'buttonUrl', 'mediaId'],
            self::Quote => ['text'],
            self::Headline => ['text'],
            self::Highlight => ['title', 'html', 'icon'],
            self::Accordion, self::Cards => ['items'],
            self::HelpSearch, self::HelpTopics, self::ChildPages => [],
        };
    }

    /**
     * Content fields that keep their meaning when a block changes from this type to $target and are
     * carried over unchanged. Only rich-text bodies qualify; fields that merely share a name (e.g. the
     * `text` of a quote and of a headline, or `mediaId` for an image and for a PDF) do not.
     *
     * @return list<string>
     */
    public function transferableFieldsTo(self $target): array
    {
        if ($this === $target) {
            return $this->contentFields();
        }

        $richTextBodies = [self::Richtext, self::Highlight];
        if (in_array($this, $richTextBodies, true) && in_array($target, $richTextBodies, true)) {
            return ['html'];
        }

        return [];
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
