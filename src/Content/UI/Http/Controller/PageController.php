<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\Content\Application\Page\PageAccessChecker;
use App\Content\Domain\Entity\Page;
use App\Content\Infrastructure\Repository\PageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PageController extends AbstractController
{
    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly PageAccessChecker $pageAccessChecker,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/{path}', name: 'app_page_show', requirements: ['path' => '.+'], methods: ['GET'], priority: -200)]
    public function show(string $path): Response
    {
        $normalizedPath = '/'.trim($path, '/');
        $page = $this->pageRepository->findPublishedByPath($normalizedPath);

        if (!$page instanceof Page) {
            throw $this->createNotFoundException();
        }

        if (!$this->pageAccessChecker->canView($page)) {
            throw $this->createAccessDeniedException($this->translator->trans('error.page.auth_required'));
        }

        return $this->render('@Content/page/show.html.twig', [
            'page' => $page,
            'breadcrumbItems' => $this->buildBreadcrumbItems($page),
        ]);
    }

    /**
     * @return list<array{label: string, path?: string}>
     */
    private function buildBreadcrumbItems(Page $page): array
    {
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
            $title = (string) $trailPage->getTitle();
            if ('' === $title) {
                $title = $this->translator->trans('page.breadcrumb.untitled');
            }

            if ($index === $lastIndex) {
                $items[] = ['label' => $title];

                continue;
            }

            $pathSegment = trim((string) $trailPage->getPath(), '/');
            $item = ['label' => $title];
            if ('' !== $pathSegment) {
                $item['path'] = $this->generateUrl('app_page_show', ['path' => $pathSegment]);
            }
            $items[] = $item;
        }

        return $items;
    }
}
