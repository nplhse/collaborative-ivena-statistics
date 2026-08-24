<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\Content\Application\Dashboard\DashboardMetricsService;
use App\Content\Application\Page\PageSidebarDataProvider;
use App\Content\Infrastructure\Repository\PostRepository;
use App\Onboarding\Application\OnboardingProgressService;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DefaultController extends AbstractController
{
    public function __construct(
        private readonly DashboardMetricsService $dashboardMetricsService,
        private readonly PostRepository $postRepository,
        private readonly PageSidebarDataProvider $pageSidebarDataProvider,
        private readonly OnboardingProgressService $onboardingProgressService,
    ) {
    }

    #[Route('/', name: 'app_default')]
    public function index(): Response
    {
        $metrics = $this->dashboardMetricsService->get();

        if ($this->isGranted('ROLE_USER')) {
            $user = $this->getUser();
            $onboardingCard = $user instanceof User
                ? $this->onboardingProgressService->buildCardForUser($user)
                : null;

            return $this->render('@Content/dashboard/dashboard.html.twig', [
                'metrics' => $metrics,
                'recentPosts' => $this->postRepository->findPublishedForIndex(5),
                'onboardingCard' => $onboardingCard,
                'pageTree' => $this->pageSidebarDataProvider->getPageTree(),
            ]);
        }

        return $this->render('@Content/public/home.html.twig', [
            'userCount' => $metrics->value('users'),
            'hospitalCount' => $metrics->value('hospitals'),
            'importCount' => $metrics->value('imports'),
            'allocationCount' => $metrics->value('allocations'),
        ]);
    }
}
