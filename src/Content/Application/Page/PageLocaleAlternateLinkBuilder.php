<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Application\Page\DTO\PageLocaleAlternateLink;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Shared\Application\Locale\SupportedLocales;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class PageLocaleAlternateLinkBuilder
{
    public function __construct(
        private PageAccessChecker $pageAccessChecker,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Published, viewable translations of the same page (including the current one).
     *
     * @return list<PageLocaleAlternateLink>
     */
    public function build(Page $page, PageTranslation $currentTranslation): array
    {
        if (!$this->pageAccessChecker->canView($page, $currentTranslation)) {
            return [];
        }

        $currentLocale = (string) $currentTranslation->getLocale();
        $links = [];

        foreach (SupportedLocales::ALL as $locale) {
            $translation = $page->translation($locale);
            if (!$translation instanceof PageTranslation || !$translation->isPublished()) {
                continue;
            }

            if (!$this->pageAccessChecker->canView($page, $translation)) {
                continue;
            }

            $pathSegment = trim((string) $translation->getPath(), '/');
            if ('' === $pathSegment) {
                continue;
            }

            $path = '/'.$pathSegment;
            $links[] = new PageLocaleAlternateLink(
                locale: $locale,
                label: $this->localeLabel($locale),
                url: $this->urlGenerator->generate('app_page_show', ['path' => $pathSegment]),
                path: $path,
                current: $locale === $currentLocale,
            );
        }

        return $links;
    }

    /**
     * @param list<PageLocaleAlternateLink> $links
     *
     * @return array<string, string> locale => absolute-path target for locale switch redirects
     */
    public function localeSwitchTargets(array $links): array
    {
        $targets = [];
        foreach ($links as $link) {
            $targets[$link->locale] = $link->path;
        }

        return $targets;
    }

    private function localeLabel(string $locale): string
    {
        return match ($locale) {
            SupportedLocales::GERMAN => 'DE',
            SupportedLocales::DEFAULT => 'EN',
            default => strtoupper($locale),
        };
    }
}
