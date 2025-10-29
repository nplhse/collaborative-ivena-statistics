<?php

// src/Controller/DashboardController.php

namespace App\Controller;

use App\Service\Statistics\PublicYearStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class DashboardController extends AbstractController
{
    #[Route('/dashboard/{year<\d{4}>}', name: 'app_stats_dashboard', defaults: ['year' => 2021])]
    public function index(
        int $year,
        PublicYearStatsService $stats,
    ): Response {
        $vm = $stats->getYearViewModel($year);

        $years = range(2021, (int) date('Y'));
        $defaultYear = 2021;

        return $this->render('dashboard/index.html.twig', [
            'vm' => $vm,
            'year' => $year,
            'years' => $years,
            'defaultYear' => $defaultYear,
        ]);
    }

    #[Route('/dashboard/switch', name: 'app_stats_dashboard_switch', methods: ['GET'])]
    public function switchYear(Request $request): Response
    {
        $year = $request->query->get('year', '');

        if (!preg_match('/^\d{4}$/', $year)) {
            return $this->redirectToRoute('app_stats_dashboard', ['year' => 2021]);
        }

        return $this->redirectToRoute('app_stats_dashboard', ['year' => (int) $year]);
    }
}
