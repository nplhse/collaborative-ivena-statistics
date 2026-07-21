<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation;

use App\Shared\Application\Navigation\Contract\NavigationPageLinksProviderInterface;
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
        'app_sitemap' => 'link.sitemap',
    ];

    /**
     * @var list<array{
     *   key: string,
     *   labelKey: string,
     *   pageKeys: list<string>,
     *   routes: list<string>
     * }>
     */
    private const array COLUMNS = [
        [
            'key' => 'project',
            'labelKey' => 'footer.column.project',
            'pageKeys' => ['about', 'features'],
            'routes' => ['app_blog_index'],
        ],
        [
            'key' => 'help',
            'labelKey' => 'footer.column.help',
            'pageKeys' => ['faq'],
            'routes' => [],
        ],
        [
            'key' => 'legal',
            'labelKey' => 'footer.column.legal',
            'pageKeys' => ['imprint', 'privacy', 'terms'],
            'routes' => [],
        ],
        [
            'key' => 'more',
            'labelKey' => 'footer.column.more',
            'pageKeys' => [],
            'routes' => ['app_sitemap', 'app_cookie_preferences'],
        ],
    ];

    public function __construct(
        private NavigationPageLinksProviderInterface $pageLinksProvider,
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
    private function buildPageLinks(string ...$pageKeys): array
    {
        $links = [];

        foreach ($this->pageLinksProvider->linksForKeys(...$pageKeys) as $pageLink) {
            $links[] = new FooterNavigationLink(
                url: $pageLink['url'],
                label: $pageLink['label'],
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
