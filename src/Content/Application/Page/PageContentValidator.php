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

        foreach ($content as $index => $block) {
            $prefix = sprintf('Block %d', (int) $index + 1);

            if (!is_array($block)) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_must_be_object', [], 'validators'));
                continue;
            }

            $type = $block['type'] ?? null;
            if (!is_string($type) || '' === trim($type)) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_type_required', [], 'validators'));
                continue;
            }

            $data = $block['data'] ?? null;
            if (!is_array($data)) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_data_must_be_object', [], 'validators'));
                continue;
            }

            if (array_key_exists('enabled', $block) && !is_bool($block['enabled'])) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_enabled_must_be_bool', [], 'validators'));
            }

            $errors = array_merge($errors, $this->validateBlock($prefix, $type, $data));
        }

        return $errors;
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
}
