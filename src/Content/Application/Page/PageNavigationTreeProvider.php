<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Application\Page\DTO\PageNavigationNode;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Repository\PageRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The page tree the current viewer may see, in the request locale.
 *
 * Built lazily on first use and kept for the rest of the request, so several page navigations and the
 * sitemap share one query. Pages without a page navigation never run it. Header and footer links use
 * their own key lookup in {@see PageNavigationProvider} and do not load the tree.
 */
final class PageNavigationTreeProvider implements ResetInterface
{
    /** Request attribute holding the ID of the page being shown; set by the page controller. */
    public const string CURRENT_PAGE_ATTRIBUTE = '_content_page_id';

    /** @var array<string, list<PageNavigationNode>> */
    private array $treeByViewer = [];

    private int $instanceCount = 0;

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly PageAccessChecker $pageAccessChecker,
        private readonly PageNavigationTreeBuilder $pageNavigationTreeBuilder,
        private readonly PageTranslationResolver $pageTranslationResolver,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @return list<PageNavigationNode>
     */
    public function visibleTree(): array
    {
        $locale = $this->currentLocale();
        // Visibility depends only on being signed in (PageAccessChecker), so that and the locale are the key.
        $viewerKey = $locale.'|'.($this->authorizationChecker->isGranted('ROLE_USER') ? 'user' : 'guest');

        return $this->treeByViewer[$viewerKey] ??= $this->buildTree($locale);
    }

    public function currentPageId(): ?int
    {
        $pageId = $this->requestStack->getMainRequest()?->attributes->get(self::CURRENT_PAGE_ATTRIBUTE);

        return \is_int($pageId) ? $pageId : null;
    }

    /**
     * Unique prefix for the HTML IDs of one navigation instance in the current request.
     */
    public function nextInstanceId(): string
    {
        return 'page-nav-'.(++$this->instanceCount);
    }

    #[\Override]
    public function reset(): void
    {
        $this->treeByViewer = [];
        $this->instanceCount = 0;
    }

    /**
     * @return list<PageNavigationNode>
     */
    private function buildTree(string $locale): array
    {
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

        return $this->toNodes($this->pageNavigationTreeBuilder->build($pages, $displayByPageId));
    }

    /**
     * @param array<int, array{page: Page, title: string, path: string, children: array<int, mixed>}> $nodes
     *
     * @return list<PageNavigationNode>
     */
    private function toNodes(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            $pageId = $node['page']->getId();
            $pathSegment = trim($node['path'], '/');
            if (null === $pageId || '' === $pathSegment) {
                continue;
            }

            /** @var array<int, array{page: Page, title: string, path: string, children: array<int, mixed>}> $children */
            $children = $node['children'];

            $result[] = new PageNavigationNode(
                pageId: $pageId,
                key: $node['page']->getKey(),
                title: $node['title'],
                url: $this->urlGenerator->generate('app_page_show', ['path' => $pathSegment]),
                children: $this->toNodes($children),
            );
        }

        return $result;
    }

    private function currentLocale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale()
            ?? $this->pageTranslationResolver->getContentDefaultLocale();
    }
}
