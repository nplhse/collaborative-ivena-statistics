<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PageTranslationResolver
{
    public function __construct(
        #[Autowire(param: 'app.content.default_locale')]
        private string $contentDefaultLocale,
    ) {
    }

    public function getContentDefaultLocale(): string
    {
        return $this->contentDefaultLocale;
    }

    /**
     * Resolve a published translation for frontend display / navigation.
     * Requested locale first, then configured content default locale.
     */
    public function resolveForDisplay(Page $page, string $requestedLocale): ?PageTranslation
    {
        $requested = $page->translation($requestedLocale);
        if ($requested instanceof PageTranslation && $requested->isPublished()) {
            return $requested;
        }

        if ($requestedLocale !== $this->contentDefaultLocale) {
            $fallback = $page->translation($this->contentDefaultLocale);
            if ($fallback instanceof PageTranslation && $fallback->isPublished()) {
                return $fallback;
            }
        }

        return null;
    }

    /**
     * Admin helper: return the translation for a locale without fallback or publish filtering.
     */
    public function resolveExact(Page $page, string $locale): ?PageTranslation
    {
        return $page->translation($locale);
    }
}
