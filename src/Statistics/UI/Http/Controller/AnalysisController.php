<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AnalysisController extends AbstractController
{
    #[Route('/statistics/analysis', name: 'app_stats_analysis', methods: ['GET'])]
    public function analysisLegacy(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute('app_stats_analytics_library', $this->libraryQuery($request));
    }

    #[Route('/statistics/pivot', name: 'app_stats_pivot_tables', methods: ['GET'])]
    public function pivotLegacy(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute('app_stats_analytics_library', $this->libraryQuery($request));
    }

    /**
     * @return array<string, string>
     */
    private function libraryQuery(Request $request): array
    {
        $query = [];
        foreach ([
            StatisticsQueryKeys::SCOPE,
            StatisticsQueryKeys::HOSPITAL,
            StatisticsQueryKeys::COHORT,
            StatisticsQueryKeys::STATE,
            StatisticsQueryKeys::DISPATCH_AREA,
            StatisticsQueryKeys::PERIOD,
            StatisticsQueryKeys::YEAR,
            StatisticsQueryKeys::MONTH,
            StatisticsQueryKeys::QUARTER,
        ] as $key) {
            if ($request->query->has($key)) {
                $query[$key] = $request->query->getString($key);
            }
        }

        return $query;
    }
}
