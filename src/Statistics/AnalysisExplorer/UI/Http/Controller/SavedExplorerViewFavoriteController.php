<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewFavoriteService;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SavedExplorerViewFavoriteController extends AbstractController
{
    public function __construct(
        private readonly SavedExplorerViewRepository $viewRepository,
        private readonly SavedExplorerViewFavoriteService $favoriteService,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(
        '/statistics/analysis/explorer/views/{id}/favorite/toggle',
        name: 'app_stats_analysis_explorer_favorite_toggle',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function toggle(
        int $id,
        Request $request,
        #[CurrentUser] User $user,
    ): \Symfony\Component\HttpFoundation\RedirectResponse {
        if (!$this->isCsrfTokenValid('explorer_favorite_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $view = $this->viewRepository->find($id);
        if (!$view instanceof \App\Statistics\Domain\Entity\SavedExplorerView) {
            throw new NotFoundHttpException();
        }

        if (!$view->isSystem() && !$view->wasCreatedBy($user)) {
            throw new NotFoundHttpException();
        }

        $this->favoriteService->toggle($user, $view);

        $referer = $request->headers->get('referer');
        if (\is_string($referer) && '' !== $referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_stats_analysis_library');
    }
}
