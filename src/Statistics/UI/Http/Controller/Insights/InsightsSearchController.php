<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller\Insights;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightSearchService;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InsightsSearchController extends AbstractController
{
    public function __construct(
        private readonly InsightSearchService $searchService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/statistics/insights/search', name: 'app_stats_insights_search', methods: ['GET'], priority: 20)]
    public function __invoke(
        Request $request,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): JsonResponse {
        $query = trim($request->query->getString('q'));
        $dimensionSlug = $request->query->get('dimension');
        $onlyDimension = \is_string($dimensionSlug) ? InsightDimensionKey::tryFrom($dimensionSlug) : null;
        $maxResults = $request->query->has('limit') && is_numeric($request->query->get('limit'))
            ? $request->query->getInt('limit')
            : null;

        $hits = $this->searchService->search($query, $request, $onlyDimension, $maxResults);
        unset($filter);
        $results = [];
        foreach ($hits as $hit) {
            $results[] = [
                'id' => $hit->id,
                'label' => $hit->label,
                'url' => $hit->url,
                'dimension' => $hit->dimension->value,
                'dimensionLabel' => $this->translator->trans(
                    'stats.insights.dimension.'.str_replace('-', '_', $hit->dimension->value).'.label',
                    [],
                    'statistics',
                ),
                'context' => $hit->contextLabel,
            ];
        }

        return new JsonResponse([
            'query' => $query,
            'results' => $results,
        ], Response::HTTP_OK);
    }
}
