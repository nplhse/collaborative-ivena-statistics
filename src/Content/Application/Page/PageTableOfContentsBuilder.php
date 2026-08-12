<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Application\Slug\SlugGenerator;

/**
 * Builds a hierarchical table of contents from page content blocks and injects
 * stable heading anchor IDs at render time (headline + richtext only).
 *
 * @phpstan-type ContentBlock array{type: string, data: array<string, mixed>, enabled?: bool}
 */
final readonly class PageTableOfContentsBuilder
{
    private const array HEADING_LEVELS = ['h1' => 1, 'h2' => 2, 'h3' => 3];

    public function __construct(
        private SlugGenerator $slugGenerator,
    ) {
    }

    /**
     * @param list<ContentBlock> $content
     */
    public function build(array $content, bool $showToc): PageTableOfContentsResult
    {
        if (!$showToc) {
            return new PageTableOfContentsResult($content, []);
        }

        $usedIds = [];
        $items = [];
        $processed = [];

        foreach ($content as $block) {
            if (false === ($block['enabled'] ?? true)) {
                $processed[] = $block;
                continue;
            }

            $type = $block['type'] ?? '';

            if ('headline' === $type) {
                $processed[] = $this->processHeadlineBlock($block, $items, $usedIds);
                continue;
            }

            if ('richtext' === $type) {
                $processed[] = $this->processRichtextBlock($block, $items, $usedIds);
                continue;
            }

            $processed[] = $block;
        }

        if ([] === $items) {
            return new PageTableOfContentsResult($content, []);
        }

        return new PageTableOfContentsResult($processed, $items);
    }

    /**
     * @param ContentBlock                                      $block
     * @param list<array{id: string, text: string, level: int}> $items
     * @param array<string, true>                               $usedIds
     *
     * @return ContentBlock
     */
    private function processHeadlineBlock(array $block, array &$items, array &$usedIds): array
    {
        $levelTag = (string) ($block['data']['level'] ?? 'h2');
        if (!isset(self::HEADING_LEVELS[$levelTag])) {
            return $block;
        }

        $text = trim(html_entity_decode(strip_tags((string) ($block['data']['text'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ('' === $text) {
            return $block;
        }

        $id = $this->uniqueId($text, $usedIds);
        $block['data']['id'] = $id;
        $items[] = [
            'id' => $id,
            'text' => $text,
            'level' => self::HEADING_LEVELS[$levelTag],
        ];

        return $block;
    }

    /**
     * @param ContentBlock                                      $block
     * @param list<array{id: string, text: string, level: int}> $items
     * @param array<string, true>                               $usedIds
     *
     * @return ContentBlock
     */
    private function processRichtextBlock(array $block, array &$items, array &$usedIds): array
    {
        $html = (string) ($block['data']['html'] ?? '');
        if ('' === trim($html)) {
            return $block;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><body>'.$html.'</body>',
            LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $block;
        }

        $xpath = new \DOMXPath($dom);
        $headings = $xpath->query('//body//h1|//body//h2|//body//h3');
        if (!$headings instanceof \DOMNodeList || 0 === $headings->length) {
            return $block;
        }

        $changed = false;
        foreach ($headings as $heading) {
            if (!$heading instanceof \DOMElement) {
                continue;
            }

            $text = trim(html_entity_decode($heading->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ('' === $text) {
                continue;
            }

            $levelTag = strtolower($heading->tagName);
            if (!isset(self::HEADING_LEVELS[$levelTag])) {
                continue;
            }

            $existingId = trim($heading->getAttribute('id'));
            if ('' !== $existingId && !isset($usedIds[$existingId])) {
                $id = $existingId;
            } else {
                $id = $this->uniqueId($text, $usedIds);
                $heading->setAttribute('id', $id);
                $changed = true;
            }

            $usedIds[$id] = true;
            $items[] = [
                'id' => $id,
                'text' => $text,
                'level' => self::HEADING_LEVELS[$levelTag],
            ];
        }

        if ($changed) {
            $block['data']['html'] = $this->innerHtml($dom);
        }

        return $block;
    }

    /**
     * @param array<string, true> $usedIds
     */
    private function uniqueId(string $text, array &$usedIds): string
    {
        try {
            $base = $this->slugGenerator->normalize($text, 80);
        } catch (\InvalidArgumentException) {
            $base = 'section';
        }

        $candidate = $base;
        $counter = 2;
        while (isset($usedIds[$candidate])) {
            $suffix = '-'.$counter;
            $candidate = rtrim(substr($base, 0, max(1, 80 - strlen($suffix))), '-').$suffix;
            ++$counter;
        }

        $usedIds[$candidate] = true;

        return $candidate;
    }

    private function innerHtml(\DOMDocument $dom): string
    {
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            return '';
        }

        $html = '';
        foreach ($body->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }

        return $html;
    }
}
