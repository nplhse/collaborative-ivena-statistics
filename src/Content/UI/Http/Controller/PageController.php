<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\Content\Application\Page\PageAccessChecker;
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

        if (!$page instanceof \App\Content\Domain\Entity\Page) {
            throw $this->createNotFoundException();
        }

        if (!$this->pageAccessChecker->canView($page)) {
            throw $this->createAccessDeniedException($this->translator->trans('error.page.auth_required'));
        }

        return $this->render('@Content/page/show.html.twig', [
            'page' => $page,
        ]);
    }
}
