<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

final readonly class PageContentBlockDataNormalizer
{
    /** @var array<string, list<string>> */
    private const array ALLOWED_FIELDS_BY_TYPE = [
        'richtext' => ['html'],
        'image' => ['mediaId', 'src', 'alt', 'caption'],
        'cta' => ['headline', 'buttonLabel', 'buttonUrl', 'mediaId', 'linkType', 'openInNewTab'],
        'quote' => ['text'],
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
                : 'richtext';

            $dataValue = $block['data'] ?? null;
            $data = is_array($dataValue) ? $dataValue : [];
            $allowed = self::ALLOWED_FIELDS_BY_TYPE[$type] ?? [];

            /** @var array<string, mixed> $filteredData */
            $filteredData = [] === $allowed
                ? []
                : array_intersect_key($data, array_flip($allowed));

            $normalizedBlock = ['type' => $type];

            if (array_key_exists('enabled', $block)) {
                $normalizedBlock['enabled'] = (bool) $block['enabled'];
            }

            $normalizedBlock['data'] = $filteredData;

            $normalized[] = $normalizedBlock;
        }

        return $normalized;
    }
}
