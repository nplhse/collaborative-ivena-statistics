<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\Content\Application\Page\PageAccessChecker;
use App\Content\Application\Page\PageLocaleAlternateLinkBuilder;
use App\Content\Application\Page\PageSidebarDataProvider;
use App\Content\Application\Page\PageTableOfContentsBuilder;
use App\Content\Application\Page\PageTranslationResolver;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Repository\PageTranslationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PageController extends AbstractController
{
    public function __construct(
        private readonly PageTranslationRepository $pageTranslationRepository,
        private readonly PageAccessChecker $pageAccessChecker,
        private readonly PageSidebarDataProvider $pageSidebarDataProvider,
        private readonly PageTranslationResolver $pageTranslationResolver,
        private readonly PageLocaleAlternateLinkBuilder $pageLocaleAlternateLinkBuilder,
        private readonly PageTableOfContentsBuilder $pageTableOfContentsBuilder,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/{path}', name: 'app_page_show', requirements: ['path' => \Symfony\Component\Routing\Requirement\Requirement::CATCH_ALL], methods: ['GET'], priority: -200)]
    public function show(string $path, Request $request): Response
    {
        $normalizedPath = '/'.trim($path, '/');
        $translation = $this->pageTranslationRepository->findPublishedByPath($normalizedPath);

        if (!$translation instanceof PageTranslation) {
            throw $this->createNotFoundException();
        }

        $page = $translation->getPage();
        if (!$page instanceof Page) {
            throw $this->createNotFoundException();
        }

        if (!$this->pageAccessChecker->canView($page, $translation)) {
            throw $this->createAccessDeniedException($this->translator->trans('error.page.auth_required', [], 'errors'));
        }

        $request->attributes->set(
            'locale_switch_targets',
            $this->pageLocaleAlternateLinkBuilder->localeSwitchTargets(
                $this->pageLocaleAlternateLinkBuilder->build($page, $translation),
            ),
        );

        $tableOfContents = $this->pageTableOfContentsBuilder->build(
            $translation->getContent(),
            $translation->isShowToc(),
        );

        return $this->render('@Content/page/show.html.twig', [
            'page' => $page,
            'translation' => $translation,
            'content' => $tableOfContents->content,
            'toc' => $tableOfContents->isEmpty() ? [] : $tableOfContents->items,
            'breadcrumbItems' => $this->buildBreadcrumbItems($page, $translation),
            'currentPage' => $page,
            ...$this->pageSidebarDataProvider->getData(),
        ]);
    }

    /**
     * @return list<array{label: string, path?: string, translatable: false}>
     */
    private function buildBreadcrumbItems(Page $page, PageTranslation $translation): array
    {
        $locale = $translation->getLocale() ?? $this->pageTranslationResolver->getContentDefaultLocale();

        /** @var list<Page> $trail */
        $trail = [];
        $current = $page;
        while ($current instanceof Page) {
            $trail[] = $current;
            $current = $current->getParent();
        }
        $trail = array_reverse($trail);

        $items = [];
        $lastIndex = \count($trail) - 1;
        foreach ($trail as $index => $trailPage) {
            $displayTranslation = $this->pageTranslationResolver->resolveForDisplay($trailPage, $locale);
            $title = $displayTranslation?->getTitle() ?? '';
            if ('' === $title) {
                $title = $this->translator->trans('page.breadcrumb.untitled', [], 'content');
            }

            if ($index === $lastIndex) {
                $items[] = ['label' => $title, 'translatable' => false];

                continue;
            }

            $ancestorTranslation = $trailPage->translation($locale);
            $pathSegment = trim($ancestorTranslation?->getPath() ?? '', '/');
            $item = ['label' => $title, 'translatable' => false];
            if ('' !== $pathSegment) {
                $item['path'] = $this->generateUrl('app_page_show', ['path' => $pathSegment]);
            }
            $items[] = $item;
        }

        return $items;
    }
}
