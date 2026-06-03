<?php

declare(strict_types=1);

namespace App\Content\Application\Blog;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

final readonly class PostContentSanitizer
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        #[Autowire(service: 'html_sanitizer.sanitizer.page_richtext')]
        private HtmlSanitizerInterface $pageRichtextSanitizer,
    ) {
    }

    public function sanitize(string $content): string
    {
        if ('' === $content) {
            return '';
        }

        $decoded = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->pageRichtextSanitizer->sanitize($decoded);
    }
}
