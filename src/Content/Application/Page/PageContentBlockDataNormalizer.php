<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Domain\Enum\PageContentBlockType;

final readonly class PageContentBlockDataNormalizer
{
    /** @var array<string, list<string>> */
    private const array ALLOWED_FIELDS_BY_TYPE = [
        PageContentBlockType::Richtext->value => ['html'],
        PageContentBlockType::Image->value => [
            'mediaId', 'src', 'alt', 'caption', 'size', 'float', 'width', 'height',
        ],
        PageContentBlockType::Cta->value => [
            'headline', 'buttonLabel', 'buttonUrl', 'mediaId', 'linkType', 'openInNewTab',
        ],
        PageContentBlockType::Quote->value => ['text'],
        PageContentBlockType::Headline->value => [
            'text', 'level', 'align', 'spacingBefore', 'spacingAfter',
        ],
        PageContentBlockType::Highlight->value => [
            'variant', 'title', 'html', 'iconMode', 'icon',
        ],
        PageContentBlockType::Accordion->value => ['items'],
    ];

    /**
     * @param list<mixed> $content
     *
     * @return list<array{type: string, data: array<string, mixed>, enabled?: bool}>
     */
    public function normalize(array $content): array
    {
        $normalized = [];

        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }

            /** @var array<string, mixed> $block */
            $typeValue = $block['type'] ?? null;
            $type = is_string($typeValue) && '' !== trim($typeValue)
                ? $typeValue
                : PageContentBlockType::Richtext->value;

            $dataValue = $block['data'] ?? null;
            $data = is_array($dataValue) ? $dataValue : [];
            $allowed = self::ALLOWED_FIELDS_BY_TYPE[$type] ?? [];

            /** @var array<string, mixed> $filteredData */
            $filteredData = [] === $allowed
                ? []
                : array_intersect_key($data, array_flip($allowed));

            if (PageContentBlockType::Accordion->value === $type) {
                $filteredData['items'] = $this->normalizeAccordionItems($data['items'] ?? []);
            }

            if (PageContentBlockType::Image->value === $type) {
                $filteredData['size'] = $this->normalizeImageSize($data);
                $filteredData['float'] = $this->normalizeImageFloat($data);
            }

            $normalizedBlock = ['type' => $type];

            if (array_key_exists('enabled', $block)) {
                $normalizedBlock['enabled'] = (bool) $block['enabled'];
            }

            $normalizedBlock['data'] = $filteredData;

            $normalized[] = $normalizedBlock;
        }

        return $normalized;
    }

    /**
     * @return list<array{title?: string, html?: string, openByDefault?: bool}>
     */
    private function normalizeAccordionItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalizedItem = [];

            if (array_key_exists('title', $item) && is_string($item['title'])) {
                $normalizedItem['title'] = $item['title'];
            }

            if (array_key_exists('html', $item) && is_string($item['html'])) {
                $normalizedItem['html'] = $item['html'];
            }

            if (array_key_exists('openByDefault', $item)) {
                $normalizedItem['openByDefault'] = (bool) $item['openByDefault'];
            }

            if ([] !== $normalizedItem) {
                $normalized[] = $normalizedItem;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function normalizeImageSize(array $data): string
    {
        $size = $data['size'] ?? null;
        if (is_string($size) && in_array($size, ['auto', 'sm', 'md', 'lg'], true)) {
            return $size;
        }

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
     */
    private function normalizeImageFloat(array $data): string
    {
        $float = (string) ($data['float'] ?? 'none');

        return in_array($float, ['none', 'left', 'right'], true) ? $float : 'none';
    }
}
