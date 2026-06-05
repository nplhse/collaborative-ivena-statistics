<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use App\Statistics\GenericAnalysis\Application\FavoriteAnalysisViewService;
use App\Statistics\GenericAnalysis\Registry\AnalysisViewRegistry;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class FavoriteAnalysisViewController extends AbstractController
{
    public function __construct(
        private readonly FavoriteAnalysisViewService $favoriteService,
        private readonly AnalysisViewRegistry $viewRegistry,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route(
        '/statistics/analytics/favorites/{viewKey}/toggle',
        name: 'app_stats_analytics_favorite_toggle',
        methods: ['POST'],
    )]
    public function toggle(
        string $viewKey,
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        if (!$this->isCsrfTokenValid('analytics_favorite_'.$viewKey, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$this->viewRegistry->has($viewKey)) {
            throw $this->createNotFoundException();
        }

        $isFavorite = $this->favoriteService->toggleSystemView($user, $viewKey);
        $referer = $request->headers->get('referer');
        if (\is_string($referer) && '' !== $referer) {
            return $this->redirect($referer);
        }

        return $this->json(['favorite' => $isFavorite]);
    }
}
