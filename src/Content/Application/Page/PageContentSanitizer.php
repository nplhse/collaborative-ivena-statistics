<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Domain\Enum\PageContentBlockType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

final readonly class PageContentSanitizer
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        #[Autowire(service: 'html_sanitizer.sanitizer.page_richtext')]
        private HtmlSanitizerInterface $pageRichtextSanitizer,
    ) {
    }

    /**
     * @param list<array{type: string, data: array<string, mixed>, enabled?: bool}> $content
     *
     * @return list<array{type: string, data: array<string, mixed>, enabled?: bool}>
     */
    public function sanitize(array $content): array
    {
        $sanitized = [];

        foreach ($content as $block) {
            $type = $block['type'] ?? null;

            if (PageContentBlockType::Richtext->value === $type) {
                $sanitized[] = $this->sanitizeHtmlField($block, 'html');
                continue;
            }

            if (PageContentBlockType::Highlight->value === $type) {
                $sanitized[] = $this->sanitizeHtmlField($block, 'html');
                continue;
            }

            if (PageContentBlockType::Accordion->value === $type) {
                $sanitized[] = $this->sanitizeAccordionBlock($block);
                continue;
            }

            $sanitized[] = $block;
        }

        return $sanitized;
    }

    /**
     * @param array{type: string, data: array<string, mixed>, enabled?: bool} $block
     *
     * @return array{type: string, data: array<string, mixed>, enabled?: bool}
     */
    private function sanitizeHtmlField(array $block, string $field): array
    {
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        $html = (string) ($data[$field] ?? '');
        $data[$field] = $this->pageRichtextSanitizer->sanitize($html);
        $block['data'] = $data;

        return $block;
    }

    /**
     * @param array{type: string, data: array<string, mixed>, enabled?: bool} $block
     *
     * @return array{type: string, data: array<string, mixed>, enabled?: bool}
     */
    private function sanitizeAccordionBlock(array $block): array
    {
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $sanitizedItems = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $html = (string) ($item['html'] ?? '');
            $item['html'] = $this->pageRichtextSanitizer->sanitize($html);
            $sanitizedItems[] = $item;
        }

        $data['items'] = $sanitizedItems;
        $block['data'] = $data;

        return $block;
    }
}
