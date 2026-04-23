<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

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
            if (($block['type'] ?? null) !== 'richtext') {
                $sanitized[] = $block;
                continue;
            }

            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $html = (string) ($data['html'] ?? '');
            $data['html'] = $this->pageRichtextSanitizer->sanitize($html);
            $block['data'] = $data;
            $sanitized[] = $block;
        }

        return $sanitized;
    }
}
