<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\AnalysisExplorer\Application\ExplorerLegacyAnalyticsViewMapper;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class LegacyAnalyticsRedirectController extends AbstractController
{
    public function __construct(
        private readonly ExplorerLegacyAnalyticsViewMapper $legacyViewMapper,
    ) {
    }

    #[Route('/statistics/analytics', name: 'app_stats_analytics', methods: ['GET'])]
    #[Route('/statistics/analytics/library', name: 'app_stats_analytics_library', methods: ['GET'])]
    public function library(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('app_stats_analysis_library', $this->filterQuery($request), 301);
    }

    #[Route('/statistics/analytics/view/{viewKey}', name: 'app_stats_analytics_view', methods: ['GET'])]
    public function view(Request $request, string $viewKey): RedirectResponse
    {
        $slug = $this->legacyViewMapper->slugForLegacyViewKey($viewKey);
        if (null === $slug) {
            return $this->redirectToRoute('app_stats_analysis_library', $this->filterQuery($request), 301);
        }

        return $this->redirectToRoute(
            'app_stats_analysis_explorer_view',
            array_merge(['view' => $slug], $this->filterQuery($request)),
            301,
        );
    }

    #[Route('/statistics/generic-analysis/{presetKey}', name: 'app_stats_generic_analysis', methods: ['GET'])]
    public function genericAnalysis(Request $request, string $presetKey): RedirectResponse
    {
        return $this->view($request, $presetKey);
    }

    #[Route('/statistics/analytics/builder', name: 'app_stats_analytics_builder', methods: ['GET'])]
    public function builder(Request $request): RedirectResponse
    {
        return $this->library($request);
    }

    #[Route('/statistics/analytics/saved/{id}', name: 'app_stats_analytics_saved', methods: ['GET'])]
    #[Route('/statistics/analytics/saved', name: 'app_stats_analytics_saved_create', methods: ['POST'])]
    #[Route('/statistics/analytics/saved/{id}', name: 'app_stats_analytics_saved_delete', methods: ['DELETE'])]
    public function saved(Request $request): RedirectResponse
    {
        return $this->library($request);
    }

    #[Route(
        '/statistics/analytics/favorites/{viewKey}/toggle',
        name: 'app_stats_analytics_favorite_toggle',
        methods: ['POST'],
    )]
    public function favoriteToggle(Request $request): RedirectResponse
    {
        return $this->library($request);
    }

    /**
     * @return array<string, string>
     */
    private function filterQuery(Request $request): array
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
