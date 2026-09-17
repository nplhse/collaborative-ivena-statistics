<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller\Insights;

use App\Statistics\Application\InsightCompare\InsightCompareSubjectRequestParser;
use App\Statistics\Application\Insights\InsightDimensionKey;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class InsightsLegacyRedirectController extends AbstractController
{
    public function __construct(
        private readonly InsightCompareSubjectRequestParser $subjectRequestParser,
    ) {
    }

    #[Route('/statistics/indication-insights', name: 'app_stats_indication_insights', methods: ['GET'])]
    public function index(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute('app_stats_insights', $request->query->all());
    }

    #[Route('/statistics/indication/{indicationId}', name: 'app_stats_indication_dashboard', requirements: ['indicationId' => '\d+'], methods: ['GET'])]
    public function indication(Request $request, int $indicationId): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute('app_stats_insights_show', array_merge(
            $request->query->all(),
            [
                'dimension' => InsightDimensionKey::Indications->value,
                'id' => $indicationId,
            ],
        ));
    }

    #[Route('/statistics/indication-group/{groupId}', name: 'app_stats_indication_group_dashboard', requirements: ['groupId' => '\d+'], methods: ['GET'])]
    public function group(Request $request, int $groupId): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute('app_stats_insights_show', array_merge(
            $request->query->all(),
            [
                'dimension' => InsightDimensionKey::IndicationGroups->value,
                'id' => $groupId,
            ],
        ));
    }

    #[Route('/statistics/indication/compare', name: 'app_stats_indication_compare', methods: ['GET'], priority: 10)]
    public function compare(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute(
            'app_stats_insights_compare',
            $this->subjectRequestParser->canonicalizeQuery($request->query->all(), InsightDimensionKey::Indications),
        );
    }
}
