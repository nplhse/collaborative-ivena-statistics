<?php

declare(strict_types=1);

namespace App\Content\Domain;

/**
 * The plain text of page content blocks, for the help search (#596): text fields of enabled blocks without markup,
 * in block order. Presentation settings (levels, alignment, media ids, URLs) are not text and are left out.
 */
final class PageContentText
{
    /** Block data keys whose values are text a reader sees. */
    private const array TEXT_KEYS = ['html', 'text', 'title', 'headline', 'caption', 'alt', 'buttonLabel', 'items', 'linkLabel'];

    /**
     * @param list<array{type: string, data: array<string, mixed>, enabled?: bool}> $content
     */
    public static function extract(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if (false === ($block['enabled'] ?? true)) {
                continue;
            }

            self::collect($block['data'], $parts);
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $parts
     */
    private static function collect(array $data, array &$parts): void
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                if (\is_int($key) || \in_array($key, self::TEXT_KEYS, true)) {
                    self::collect($value, $parts);
                }

                continue;
            }

            if (\is_string($value) && \is_string($key) && \in_array($key, self::TEXT_KEYS, true)) {
                $parts[] = html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</li>', '</h'], [' <br', ' </p>', ' </li>', ' </h'], $value)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            }
        }
    }
}
