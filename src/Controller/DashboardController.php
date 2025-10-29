<?php

namespace App\Controller;

use App\Service\Statistics\DashboardStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/', name: 'app_default')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private DashboardStatsService $statsService,
    ) {
    }

    public function __invoke(): Response
    {
        $stats = $this->statsService->getStats();

        return $this->render('dashboard/index.html.twig', [
            'stats' => $stats,
        ]);
    }
}
