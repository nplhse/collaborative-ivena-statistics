<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewService;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

final class DeleteSavedExplorerViewController extends AbstractController
{
    public function __construct(
        private readonly SavedExplorerViewRepository $viewRepository,
        private readonly SavedExplorerViewService $savedViewService,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(
        '/statistics/analysis/explorer/views/{id}/delete',
        name: 'app_stats_analysis_explorer_view_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function __invoke(
        int $id,
        Request $request,
        #[CurrentUser] User $user,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('explorer_view_delete_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $view = $this->viewRepository->find($id);
        if (!$view instanceof SavedExplorerView || !$view->isEditableBy($user)) {
            throw new NotFoundHttpException();
        }

        $this->savedViewService->delete($view, $user);
        $this->addFlash('success', new TranslatableMessage('stats.analysis_explorer.delete.flash', domain: 'statistics'));

        return $this->redirectToRoute('app_stats_analysis_library');
    }
}
