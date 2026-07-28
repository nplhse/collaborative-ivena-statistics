<?php

declare(strict_types=1);

namespace App\Content\Application\Blog;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

final readonly class PostContentSanitizer
{
    public const int PLAIN_PREVIEW_MAX_LENGTH = 260;

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

    /**
     * Safe HTML snippet for blog list cards (first paragraph or truncated plain text).
     */
    public function preview(string $content, int $plainMaxLength = self::PLAIN_PREVIEW_MAX_LENGTH): string
    {
        $safe = $this->sanitize($content);
        if ('' === $safe) {
            return '';
        }

        if (str_contains($safe, '</p>')) {
            return explode('</p>', $safe, 2)[0].'</p>';
        }

        $text = strip_tags($safe);
        if (mb_strlen($text) > $plainMaxLength) {
            $text = mb_substr($text, 0, $plainMaxLength).'...';
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
