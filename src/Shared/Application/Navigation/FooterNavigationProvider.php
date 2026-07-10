<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation;

use App\Content\Application\Page\PageNavigationProvider;
use App\Content\Domain\Enum\PageKey;
use App\Shared\Application\Navigation\DTO\FooterNavigationColumn;
use App\Shared\Application\Navigation\DTO\FooterNavigationLink;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class FooterNavigationProvider
{
    /** @var array<string, string> */
    private const array ROUTE_LABEL_KEYS = [
        'app_blog_index' => 'link.blog',
        'app_cookie_preferences' => 'link.cookie_preferences',
    ];

    /**
     * @var list<array{
     *   key: string,
     *   labelKey: string,
     *   pageKeys: list<PageKey>,
     *   routes: list<string>
     * }>
     */
    private const array COLUMNS = [
        [
            'key' => 'project',
            'labelKey' => 'footer.column.project',
            'pageKeys' => [PageKey::About, PageKey::Features],
            'routes' => ['app_blog_index'],
        ],
        [
            'key' => 'help',
            'labelKey' => 'footer.column.help',
            'pageKeys' => [PageKey::Faq],
            'routes' => [],
        ],
        [
            'key' => 'legal',
            'labelKey' => 'footer.column.legal',
            'pageKeys' => [PageKey::Imprint, PageKey::Privacy, PageKey::Terms],
            'routes' => [],
        ],
        [
            'key' => 'more',
            'labelKey' => 'footer.column.more',
            'pageKeys' => [],
            'routes' => ['app_cookie_preferences'],
        ],
    ];

    public function __construct(
        private PageNavigationProvider $pageNavigationProvider,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<FooterNavigationColumn>
     */
    public function getColumns(): array
    {
        $columns = [];

        foreach (self::COLUMNS as $columnDefinition) {
            $links = $this->buildPageLinks(...$columnDefinition['pageKeys']);
            $links = [...$links, ...$this->buildRouteLinks(...$columnDefinition['routes'])];

            if ([] === $links) {
                continue;
            }

            $columns[] = new FooterNavigationColumn(
                key: $columnDefinition['key'],
                labelKey: $columnDefinition['labelKey'],
                links: $links,
            );
        }

        return $columns;
    }

    /**
     * @return list<FooterNavigationLink>
     */
    private function buildPageLinks(PageKey ...$pageKeys): array
    {
        $links = [];

        foreach ($pageKeys as $pageKey) {
            $page = $this->pageNavigationProvider->getPageByKey($pageKey);
            $url = $this->pageNavigationProvider->getUrlByKey($pageKey);
            if (!$page instanceof \App\Content\Domain\Entity\Page || null === $url) {
                continue;
            }

            $links[] = new FooterNavigationLink(
                url: $url,
                label: (string) $page->getTitle(),
            );
        }

        return $links;
    }

    /**
     * @return list<FooterNavigationLink>
     */
    private function buildRouteLinks(string ...$routeNames): array
    {
        $links = [];

        foreach ($routeNames as $routeName) {
            $labelKey = self::ROUTE_LABEL_KEYS[$routeName] ?? null;
            if (null === $labelKey) {
                continue;
            }

            $links[] = new FooterNavigationLink(
                url: $this->urlGenerator->generate($routeName),
                label: $this->translator->trans($labelKey, [], 'shared'),
            );
        }

        return $links;
    }
}
