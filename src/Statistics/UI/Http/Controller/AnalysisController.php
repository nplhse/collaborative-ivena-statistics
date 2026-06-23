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
    public function __invoke(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $analysis = $request->query->getString(StatisticsQueryKeys::ANALYSIS);

        if (\in_array($analysis, ['allocation_pivot', 'hospital_pivot', 'pivot'], true)) {
            $query = $request->query->all();
            if ('pivot' === $analysis) {
                $query[StatisticsQueryKeys::ANALYSIS] = 'allocation_pivot';
            }

            return $this->redirectToRoute('app_stats_pivot_tables', $query);
        }

        return $this->redirectToRoute('app_stats_analysis_library', $this->libraryQuery($request));
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
