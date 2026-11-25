<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\PageContentBlockType;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class PageContentValidator
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /** @var array<string, list<string>> */
    private const array REQUIRED_FIELDS = [
        PageContentBlockType::Richtext->value => ['html'],
        PageContentBlockType::Quote->value => ['text'],
        PageContentBlockType::Headline->value => ['text'],
        PageContentBlockType::Highlight->value => ['html'],
    ];

    /**
     * Blocks without settings: their content comes from the page tree when the page is shown.
     *
     * @var list<string>
     */
    private const array BLOCKS_WITHOUT_FIELDS = [
        PageContentBlockType::HelpSearch->value,
        PageContentBlockType::HelpTopics->value,
    ];

    public const int MAX_CARDS = 4;

    public const int MAX_READ_MORE_LINKS = 8;

    /** Icon names as the highlight block uses them, e.g. tabler:info-circle. */
    private const string ICON_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*:[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** Card links: a path on this site (also with anchor or query) or an http(s) address. */
    private const string LINK_PATTERN = '~^(?:/(?!/)\S*|#\S+|https?://\S+)$~i';

    /** @var list<string> */
    private const array HEADLINE_LEVELS = ['h1', 'h2', 'h3', 'h4'];

    /** @var list<string> */
    private const array HEADLINE_ALIGNS = ['left', 'center', 'right'];

    /** @var list<string> */
    private const array SPACING_OPTIONS = ['none', 'sm', 'md', 'lg'];

    /** @var list<string> */
    private const array HIGHLIGHT_VARIANTS = ['info', 'success', 'warning', 'danger', 'note'];

    /** @var list<string> */
    private const array HIGHLIGHT_ICON_MODES = ['auto', 'custom', 'none'];

    /** @var list<string> */
    private const array IMAGE_SIZES = ['auto', 'sm', 'md', 'lg'];

    /** @var list<string> */
    private const array IMAGE_FLOATS = ['none', 'left', 'right'];

    /**
     * @return list<string>
     */
    public function validate(mixed $content): array
    {
        $errors = [];

        if (!is_array($content)) {
            return [$this->translator->trans('page.validation.content_must_be_array', [], 'validators')];
        }

        $position = 1;
        foreach ($content as $block) {
            $errors = array_merge($errors, $this->validateBlockAt($position, $block));
            ++$position;
        }

        return $errors;
    }

    /**
     * Validates one block; $position is its 1-based place in the editor and appears in the messages.
     *
     * @return list<string>
     */
    public function validateBlockAt(int $position, mixed $block): array
    {
        $prefix = sprintf('Block %d', $position);

        if (!is_array($block)) {
            return [sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_must_be_object', [], 'validators'))];
        }

        $type = $block['type'] ?? null;
        if (!is_string($type) || '' === trim($type)) {
            return [sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_type_required', [], 'validators'))];
        }

        $data = $block['data'] ?? null;
        if (!is_array($data)) {
            return [sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_data_must_be_object', [], 'validators'))];
        }

        $errors = [];
        if (array_key_exists('enabled', $block) && !is_bool($block['enabled'])) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_enabled_must_be_bool', [], 'validators'));
        }

        /* @var array<string, mixed> $data */
        return array_merge($errors, $this->validateBlock($prefix, $type, $data));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function validateBlock(string $prefix, string $type, array $data): array
    {
        if (PageContentBlockType::Image->value === $type) {
            return $this->validateImageBlock($prefix, $data);
        }

        if (PageContentBlockType::Cta->value === $type) {
            return $this->validateCtaBlock($prefix, $data);
        }

        if (PageContentBlockType::Headline->value === $type) {
            return $this->validateHeadlineBlock($prefix, $data);
        }

        if (PageContentBlockType::Highlight->value === $type) {
            return $this->validateHighlightBlock($prefix, $data);
        }

        if (PageContentBlockType::Accordion->value === $type) {
            return $this->validateAccordionBlock($prefix, $data);
        }

        if (PageContentBlockType::Cards->value === $type) {
            return $this->validateCardsBlock($prefix, $data);
        }

        if (PageContentBlockType::ReadMore->value === $type) {
            return $this->validateReadMoreBlock($prefix, $data);
        }

        if (PageContentBlockType::ChildPages->value === $type) {
            return array_key_exists('numbered', $data) && !is_bool($data['numbered'])
                ? [sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.child_pages_numbered_must_be_bool', [], 'validators'))]
                : [];
        }

        if (in_array($type, self::BLOCKS_WITHOUT_FIELDS, true)) {
            return [];
        }

        if (!array_key_exists($type, self::REQUIRED_FIELDS)) {
            return [sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_unknown_type', ['{type}' => $type], 'validators'))];
        }

        $errors = [];
        foreach (self::REQUIRED_FIELDS[$type] as $fieldName) {
            if (!$this->isNonEmptyString($data[$fieldName] ?? null)) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => $fieldName], 'validators'));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function validateImageBlock(string $prefix, array $data): array
    {
        $errors = [];

        if (!$this->isNonEmptyString($data['alt'] ?? null)) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => 'alt'], 'validators'));
        }

        $hasMedia = $this->hasMediaReference($data['mediaId'] ?? null);
        $hasSrc = $this->isNonEmptyString($data['src'] ?? null);

        if (!$hasMedia && !$hasSrc) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.image_src_or_media_required', [], 'validators'));
        }

        $size = (string) ($data['size'] ?? $this->resolveLegacyImageSize($data));
        if ('' !== $size && !in_array($size, self::IMAGE_SIZES, true)) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.image_invalid_size', [], 'validators'));
        }

        $float = (string) ($data['float'] ?? 'none');
        if ('' !== $float && !in_array($float, self::IMAGE_FLOATS, true)) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.image_invalid_float', [], 'validators'));
        }

        if (in_array($float, ['left', 'right'], true) && 'lg' === $size) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.image_float_requires_non_full_width', [], 'validators'));
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveLegacyImageSize(array $data): string
    {
        $preset = (string) ($data['widthPreset'] ?? '');

        return match ($preset) {
            'sm' => 'sm',
            'md' => 'md',
            'lg' => 'lg',
            default => 'auto',
        };
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function validateCtaBlock(string $prefix, array $data): array
    {
        $errors = [];

        foreach (['headline', 'buttonLabel'] as $fieldName) {
            if (!$this->isNonEmptyString($data[$fieldName] ?? null)) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => $fieldName], 'validators'));
            }
        }

        $linkType = (string) ($data['linkType'] ?? 'url');

        if ('media' === $linkType) {
            if (!$this->hasMediaReference($data['mediaId'] ?? null)) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.cta_media_required', [], 'validators'));
            }
        } elseif (!$this->isNonEmptyString($data['buttonUrl'] ?? null)) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => 'buttonUrl'], 'validators'));
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function validateHeadlineBlock(string $prefix, array $data): array
    {
        $errors = [];

        if (!$this->isNonEmptyString($data['text'] ?? null)) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => 'text'], 'validators'));
        }

        $level = (string) ($data['level'] ?? 'h2');
        if ('' !== $level && !in_array($level, self::HEADLINE_LEVELS, true)) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.headline_invalid_level', [], 'validators'));
        }

        $align = (string) ($data['align'] ?? 'left');
        if ('' !== $align && !in_array($align, self::HEADLINE_ALIGNS, true)) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.headline_invalid_align', [], 'validators'));
        }

        foreach (['spacingBefore', 'spacingAfter'] as $field) {
            $value = (string) ($data[$field] ?? '');
            if ('' !== $value && !in_array($value, self::SPACING_OPTIONS, true)) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.headline_invalid_spacing', ['{field}' => $field], 'validators'));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function validateHighlightBlock(string $prefix, array $data): array
    {
        $errors = [];

        if (!$this->isNonEmptyString($data['html'] ?? null)) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => 'html'], 'validators'));
        }

        $variant = (string) ($data['variant'] ?? 'info');
        if ('' !== $variant && !in_array($variant, self::HIGHLIGHT_VARIANTS, true)) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.highlight_invalid_variant', [], 'validators'));
        }

        $iconMode = (string) ($data['iconMode'] ?? 'auto');
        if ('' !== $iconMode && !in_array($iconMode, self::HIGHLIGHT_ICON_MODES, true)) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.highlight_invalid_icon_mode', [], 'validators'));
        }

        if ('custom' === $iconMode && !$this->isNonEmptyString($data['icon'] ?? null)) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.highlight_icon_required', [], 'validators'));
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function validateAccordionBlock(string $prefix, array $data): array
    {
        $errors = [];
        $items = $data['items'] ?? null;

        if (!is_array($items) || [] === $items) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.accordion_items_required', [], 'validators'));

            return $errors;
        }

        foreach ($items as $itemIndex => $item) {
            $itemPrefix = sprintf('%s item %d', $prefix, (int) $itemIndex + 1);

            if (!is_array($item)) {
                $errors[] = sprintf('%s: %s', $itemPrefix, $this->translator->trans('page.validation.accordion_item_must_be_object', [], 'validators'));
                continue;
            }

            if (!$this->isNonEmptyString($item['title'] ?? null)) {
                $errors[] = sprintf('%s: %s', $itemPrefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => 'title'], 'validators'));
            }

            if (!$this->isNonEmptyString($item['html'] ?? null)) {
                $errors[] = sprintf('%s: %s', $itemPrefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => 'html'], 'validators'));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function validateCardsBlock(string $prefix, array $data): array
    {
        $items = $data['items'] ?? null;

        if (!is_array($items) || [] === $items) {
            return [sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.cards_items_required', [], 'validators'))];
        }

        $errors = [];
        if (count($items) > self::MAX_CARDS) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.cards_too_many', ['{max}' => self::MAX_CARDS], 'validators'));
        }

        foreach (array_values($items) as $itemIndex => $item) {
            $itemPrefix = sprintf('%s card %d', $prefix, $itemIndex + 1);

            if (!is_array($item)) {
                $errors[] = sprintf('%s: %s', $itemPrefix, $this->translator->trans('page.validation.accordion_item_must_be_object', [], 'validators'));
                continue;
            }

            foreach (['title', 'html'] as $fieldName) {
                if (!$this->isNonEmptyString($item[$fieldName] ?? null)) {
                    $errors[] = sprintf('%s: %s', $itemPrefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => $fieldName], 'validators'));
                }
            }

            $icon = $item['icon'] ?? '';
            if ($this->isNonEmptyString($icon) && 1 !== preg_match(self::ICON_PATTERN, (string) $icon)) {
                $errors[] = sprintf('%s: %s', $itemPrefix, $this->translator->trans('page.validation.cards_invalid_icon', [], 'validators'));
            }

            $linkUrl = $item['linkUrl'] ?? '';
            if ($this->isNonEmptyString($linkUrl) && 1 !== preg_match(self::LINK_PATTERN, (string) $linkUrl)) {
                $errors[] = sprintf('%s: %s', $itemPrefix, $this->translator->trans('page.validation.cards_invalid_link', [], 'validators'));
            }

            if ($this->isNonEmptyString($item['linkLabel'] ?? null) && !$this->isNonEmptyString($item['linkUrl'] ?? null)) {
                $errors[] = sprintf('%s: %s', $itemPrefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => 'linkUrl'], 'validators'));
            }
        }

        return $errors;
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && '' !== trim($value);
    }

    private function hasMediaReference(mixed $value): bool
    {
        if ($value instanceof Media) {
            return null !== $value->getId();
        }

        if (is_int($value) && $value > 0) {
            return true;
        }

        return is_string($value) && '' !== $value && ctype_digit($value);
    }

    public function assertValid(mixed $content): void
    {
        $errors = $this->validate($content);

        if ([] !== $errors) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }
    }

    /**
     * "Learn more" block (#1590): an optional heading and one to eight links, each a path on this site or an
     * http(s) address with an optional link text.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function validateReadMoreBlock(string $prefix, array $data): array
    {
        $errors = [];
        if (array_key_exists('title', $data) && !is_string($data['title'])) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => 'title'], 'validators'));
        }

        $items = $data['items'] ?? null;
        if (!is_array($items) || [] === $items) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.read_more_items_required', [], 'validators'));

            return $errors;
        }

        if (count($items) > self::MAX_READ_MORE_LINKS) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.read_more_too_many', ['{max}' => self::MAX_READ_MORE_LINKS], 'validators'));
        }

        foreach (array_values($items) as $itemIndex => $item) {
            $itemPrefix = sprintf('%s link %d', $prefix, $itemIndex + 1);

            if (!is_array($item)) {
                $errors[] = sprintf('%s: %s', $itemPrefix, $this->translator->trans('page.validation.accordion_item_must_be_object', [], 'validators'));
                continue;
            }

            $linkUrl = $item['linkUrl'] ?? null;
            if (!$this->isNonEmptyString($linkUrl)) {
                $errors[] = sprintf('%s: %s', $itemPrefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => 'linkUrl'], 'validators'));
            } elseif (1 !== preg_match(self::LINK_PATTERN, (string) $linkUrl)) {
                $errors[] = sprintf('%s: %s', $itemPrefix, $this->translator->trans('page.validation.cards_invalid_link', [], 'validators'));
            }
        }

        return $errors;
    }
}
