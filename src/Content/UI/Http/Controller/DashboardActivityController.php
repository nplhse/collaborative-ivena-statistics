<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\User\Application\Explore\ProjectActivityPage;
use App\User\Application\Explore\ProjectActivityQueryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** @psalm-suppress UnusedClass */
#[IsGranted('ROLE_USER')]
final class DashboardActivityController extends AbstractController
{
    public function __construct(
        private readonly ProjectActivityQueryInterface $activityQuery,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/activity', name: 'app_dashboard_activity', methods: ['GET'])]
    public function __invoke(): Response
    {
        $frameId = 'dashboard-activity';

        try {
            $page = $this->activityQuery->getPage(null, ProjectActivityPage::PREVIEW_SIZE);
        } catch (\Throwable $exception) {
            $this->logger->error('Dashboard activity feed failed.', [
                'exception' => $exception,
            ]);

            return $this->render('@Content/dashboard/_activity_error.html.twig', [
                'frameId' => $frameId,
            ]);
        }

        return $this->render('@Content/dashboard/_activity_page.html.twig', [
            'page' => $page,
            'frameId' => $frameId,
        ]);
    }
}
