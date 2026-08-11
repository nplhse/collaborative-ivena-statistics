<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Repository\PageRepository;
use App\Content\Infrastructure\Repository\PostRepository;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class PageSidebarDataProvider
{
    public function __construct(
        private PageRepository $pageRepository,
        private PageAccessChecker $pageAccessChecker,
        private PageNavigationTreeBuilder $pageNavigationTreeBuilder,
        private PageTranslationResolver $pageTranslationResolver,
        private RequestStack $requestStack,
        private PostRepository $postRepository,
    ) {
    }

    /**
     * @return array{
     *   pageTree: array<int, array{page: Page, title: string, path: string, children: array<int, mixed>}>,
     *   latest_posts: list<\App\Content\Domain\Entity\Post>
     * }
     */
    public function getData(): array
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale()
            ?? $this->pageTranslationResolver->getContentDefaultLocale();

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

        return [
            'pageTree' => $this->pageNavigationTreeBuilder->build($pages, $displayByPageId),
            'latest_posts' => $this->postRepository->findPublishedForIndex(5),
        ];
    }
}
