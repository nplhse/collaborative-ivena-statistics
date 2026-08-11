<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Application\Page\DTO\PageNavigationLink;
use App\Content\Application\Page\DTO\PublishedPageLink;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Repository\PageRepository;
use App\Shared\Application\Navigation\Contract\NavigationPageLinksProviderInterface;
use App\Shared\Application\Navigation\DTO\SitemapPageNode;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[AsAlias(NavigationPageLinksProviderInterface::class)]
final class PageNavigationProvider implements NavigationPageLinksProviderInterface
{
    /** @var list<PageKey> */
    private const array GUEST_ONLY_HEADER_KEYS = [
        PageKey::About,
        PageKey::Features,
    ];

    /** @var list<PageKey> */
    private const array SHARED_HEADER_KEYS = [
        PageKey::Faq,
    ];

    /** @var array<string, Page>|null */
    private ?array $publishedByKey = null;

    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PageAccessChecker $pageAccessChecker,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly PageNavigationTreeBuilder $pageNavigationTreeBuilder,
        private readonly PageTranslationResolver $pageTranslationResolver,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getPageByKey(PageKey $key): ?Page
    {
        $page = $this->getPublishedByKey()[$key->value] ?? null;
        if (!$page instanceof Page) {
            return null;
        }

        $translation = $this->resolveTranslation($page);
        if (!$translation instanceof PageTranslation) {
            return null;
        }

        if (!$this->pageAccessChecker->canView($page, $translation)) {
            return null;
        }

        return $page;
    }

    public function getUrlByKey(PageKey $key): ?string
    {
        $page = $this->getPageByKey($key);
        if (!$page instanceof Page) {
            return null;
        }

        $translation = $this->resolveTranslation($page);
        if (!$translation instanceof PageTranslation) {
            return null;
        }

        return $this->buildUrlFromPath($translation->getPath());
    }

    /**
     * @return list<array{url: string, label: string}>
     */
    #[\Override]
    public function linksForKeys(string ...$keys): array
    {
        $links = [];

        foreach ($keys as $keyValue) {
            $pageKey = PageKey::tryFrom($keyValue);
            if (!$pageKey instanceof PageKey) {
                continue;
            }

            $page = $this->getPageByKey($pageKey);
            $url = $this->getUrlByKey($pageKey);
            if (!$page instanceof Page || null === $url) {
                continue;
            }

            $translation = $this->resolveTranslation($page);
            if (!$translation instanceof PageTranslation) {
                continue;
            }

            $links[] = [
                'url' => $url,
                'label' => (string) $translation->getTitle(),
            ];
        }

        return $links;
    }

    /**
     * @return list<PageNavigationLink>
     */
    public function getHeaderPages(): array
    {
        $keys = self::SHARED_HEADER_KEYS;
        if (!$this->authorizationChecker->isGranted('ROLE_USER')) {
            $keys = [...self::GUEST_ONLY_HEADER_KEYS, ...$keys];
        }

        return $this->buildLinksForKeys(...$keys);
    }

    /**
     * @return list<PageNavigationLink>
     */
    public function getFooterPages(): array
    {
        return $this->buildLinksForKeys(
            PageKey::Imprint,
            PageKey::Privacy,
            PageKey::Terms,
        );
    }

    /**
     * @return list<SitemapPageNode>
     */
    #[\Override]
    public function visiblePublishedPageTree(): array
    {
        return $this->getVisiblePublishedPageTree();
    }

    /**
     * @return list<PublishedPageLink>
     */
    public function getVisiblePublishedPageLinks(): array
    {
        return $this->flattenPageTree($this->getVisiblePublishedPageTree());
    }

    /**
     * @return list<SitemapPageNode>
     */
    public function getVisiblePublishedPageTree(): array
    {
        [$pages, $displayByPageId] = $this->collectVisiblePagesWithDisplay();

        return $this->buildSitemapPageNodes($this->pageNavigationTreeBuilder->build($pages, $displayByPageId));
    }

    /**
     * @param array<int, array{page: Page, title: string, path: string, children: array<int, mixed>}> $nodes
     *
     * @return list<SitemapPageNode>
     */
    private function buildSitemapPageNodes(array $nodes): array
    {
        $tree = [];

        foreach ($nodes as $node) {
            $url = $this->buildUrlFromPath($node['path']);
            if (null === $url) {
                continue;
            }

            $tree[] = new SitemapPageNode(
                url: $url,
                label: $node['title'],
                children: $this->buildSitemapPageNodes($node['children']),
            );
        }

        return $tree;
    }

    /**
     * @param list<SitemapPageNode> $nodes
     *
     * @return list<PublishedPageLink>
     */
    private function flattenPageTree(array $nodes): array
    {
        $links = [];

        foreach ($nodes as $node) {
            $links[] = new PublishedPageLink(
                url: $node->url,
                label: $node->label,
            );
            $links = [...$links, ...$this->flattenPageTree($node->children)];
        }

        return $links;
    }

    /**
     * @return array<string, Page>
     */
    private function getPublishedByKey(): array
    {
        if (null !== $this->publishedByKey) {
            return $this->publishedByKey;
        }

        $this->publishedByKey = [];
        foreach ($this->pageRepository->findAllWithPublishedTranslationAndKey() as $page) {
            $key = $page->getKey();
            if (!$key instanceof PageKey) {
                continue;
            }

            $this->publishedByKey[$key->value] = $page;
        }

        return $this->publishedByKey;
    }

    /**
     * @return list<PageNavigationLink>
     */
    private function buildLinksForKeys(PageKey ...$keys): array
    {
        $links = [];
        foreach ($keys as $key) {
            $page = $this->getPageByKey($key);
            if (!$page instanceof Page) {
                continue;
            }

            $translation = $this->resolveTranslation($page);
            if (!$translation instanceof PageTranslation) {
                continue;
            }

            $url = $this->buildUrlFromPath($translation->getPath());
            if (null === $url) {
                continue;
            }

            $links[] = new PageNavigationLink(
                key: $key,
                page: $page,
                url: $url,
                label: (string) $translation->getTitle(),
            );
        }

        return $links;
    }

    /**
     * @return array{0: list<Page>, 1: array<int, array{title: string, path: string}>}
     */
    private function collectVisiblePagesWithDisplay(): array
    {
        $locale = $this->currentLocale();
        $pages = [];
        /** @var array<int, array{title: string, path: string}> $displayByPageId */
        $displayByPageId = [];

        foreach ($this->pageRepository->findAllWithPublishedTranslation() as $page) {
            $translation = $this->pageTranslationResolver->resolveForDisplay($page, $locale);
            if (!$translation instanceof PageTranslation) {
                continue;
            }

            if (!$this->pageAccessChecker->canView($page, $translation)) {
                continue;
            }

            $pageId = $page->getId();
            if (null === $pageId) {
                continue;
            }

            $pages[] = $page;
            $displayByPageId[$pageId] = [
                'title' => (string) $translation->getTitle(),
                'path' => (string) $translation->getPath(),
            ];
        }

        return [$pages, $displayByPageId];
    }

    private function resolveTranslation(Page $page): ?PageTranslation
    {
        return $this->pageTranslationResolver->resolveForDisplay($page, $this->currentLocale());
    }

    private function currentLocale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale()
            ?? $this->pageTranslationResolver->getContentDefaultLocale();
    }

    private function buildUrlFromPath(?string $path): ?string
    {
        $pathSegment = trim((string) $path, '/');
        if ('' === $pathSegment) {
            return null;
        }

        return $this->urlGenerator->generate('app_page_show', ['path' => $pathSegment]);
    }
}
