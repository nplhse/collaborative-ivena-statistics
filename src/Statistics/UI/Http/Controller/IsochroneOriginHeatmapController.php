<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\IndicationDashboard\IndicationSubject;
use App\Statistics\Application\IndicationDashboard\IndicationSubjectResolver;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightDimensionRegistry;
use App\Statistics\Application\Insights\InsightPopulationFilter;
use App\Statistics\Application\IsochroneOriginMap\Dto\IsochroneOriginHeatmapView;
use App\Statistics\Application\IsochroneOriginMap\IsochroneOriginHeatmapAssembler;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\CaseFlow\Application\CaseFlowGeoKeyResolver;
use App\Statistics\CaseFlow\Application\CaseFlowPrivacySuppressor;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMode;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowDispatchAreaMatch;
use App\Statistics\CaseFlow\Infrastructure\Query\CaseFlowOriginDistributionQuery;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowOriginRow;
use App\Statistics\GeographicMap\Application\DTO\GeographicMapLayer;
use App\Statistics\GeographicMap\Application\GeographicMapPayloadBuilder;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class IsochroneOriginHeatmapController extends AbstractController
{
    public function __construct(
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsScopeResolver $statisticsScopeResolver,
        private readonly IsochroneOriginHeatmapAssembler $assembler,
        private readonly IndicationSubjectResolver $subjectResolver,
        private readonly InsightDimensionRegistry $insightDimensionRegistry,
        private readonly CaseFlowOriginDistributionQuery $originDistributionQuery,
        private readonly CaseFlowPrivacySuppressor $privacySuppressor,
        private readonly CaseFlowGeoKeyResolver $geoKeyResolver,
        private readonly GeographicMapPayloadBuilder $geographicMapPayloadBuilder,
    ) {
    }

    #[Route('/statistics/widgets/isochrone-origin-map', name: 'app_stats_isochrone_origin_map', methods: ['GET'])]
    public function frame(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $context = $this->statisticsContextFactory->create($user, $filter);
        $scope = $this->statisticsScopeResolver->resolveCriteria($context);
        $period = StatisticsPeriodResolver::resolve($filter);
        $insightsWidget = $this->isInsightsWidget($request);
        $population = $this->resolvePopulation($request);

        $heatmap = $this->assembler->build(
            $filter,
            $scope,
            $period,
            null,
            $this->resolveDepartmentWasClosed($request),
            null,
            $population,
        );

        $mapPayload = null;
        if ($insightsWidget && $heatmap instanceof IsochroneOriginHeatmapView) {
            $mapPayload = $this->buildInsightsMapPayload($heatmap, $period, $scope, $population);
        }

        return $this->render('@Statistics/isochrone_origin_map/_frame.html.twig', [
            'heatmap' => $heatmap,
            'mapPayload' => $mapPayload,
            'showOriginLayers' => $insightsWidget && $heatmap instanceof IsochroneOriginHeatmapView,
        ]);
    }

    private function isInsightsWidget(Request $request): bool
    {
        $dimension = $request->query->get('dimension');

        return \is_string($dimension) && '' !== $dimension && null !== $this->positiveIntQuery($request, 'id');
    }

    private function resolvePopulation(Request $request): ?InsightPopulationFilter
    {
        $dimension = $request->query->get('dimension');
        $id = $this->positiveIntQuery($request, 'id');
        if (\is_string($dimension) && '' !== $dimension && null !== $id) {
            $dimensionKey = InsightDimensionKey::tryFrom($dimension);
            if (!$dimensionKey instanceof InsightDimensionKey) {
                return InsightPopulationFilter::indications([]);
            }

            $subject = $this->insightDimensionRegistry->get($dimensionKey)->resolve($id);
            if (!$subject instanceof \App\Statistics\Application\Insights\InsightSubject) {
                return InsightPopulationFilter::indications([]);
            }

            return $subject->population;
        }

        $indicationIds = $this->resolveIndicationIds($request);

        return \is_array($indicationIds) ? InsightPopulationFilter::indications($indicationIds) : null;
    }

    /**
     * @return list<int>|null
     */
    private function resolveIndicationIds(Request $request): ?array
    {
        $indicationId = $this->positiveIntQuery($request, 'indicationId');
        if (null !== $indicationId) {
            $subject = $this->subjectResolver->resolveSingle($indicationId);
            if (!$subject instanceof IndicationSubject) {
                return [];
            }

            return $subject->indicationIds;
        }

        $groupId = $this->positiveIntQuery($request, 'groupId');
        if (null !== $groupId) {
            $subject = $this->subjectResolver->resolveGroup($groupId);
            if (!$subject instanceof IndicationSubject) {
                return [];
            }

            return $subject->indicationIds;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInsightsMapPayload(
        IsochroneOriginHeatmapView $heatmap,
        StatisticsPeriodBounds $period,
        StatisticsScopeCriteria $scope,
        ?InsightPopulationFilter $population,
    ): array {
        $originRows = $this->originDistributionQuery->fetch(
            $period->from,
            $period->toExclusive,
            $scope,
            null,
            null,
            CaseFlowDispatchAreaMatch::Related,
            $population,
        );
        $originTotal = array_sum(array_map(
            static fn (CaseFlowOriginRow $row): int => $row->caseCount,
            $originRows,
        ));

        return $this->geographicMapPayloadBuilder->build(
            CaseFlowMode::HospitalOrigin,
            $this->privacySuppressor->buildMapFeatures(
                $originRows,
                $originTotal,
                fn (int $dispatchAreaId, string $name): string => $this->geoKeyResolver->resolve($dispatchAreaId, $name),
            ),
            [],
            $heatmap,
            true,
            compactEnabledLayers: [
                GeographicMapLayer::IsochroneBands,
                GeographicMapLayer::HospitalPin,
            ],
        );
    }

    private function resolveDepartmentWasClosed(Request $request): ?bool
    {
        $raw = $request->query->get('departmentWasClosed');
        if (null === $raw || '' === $raw) {
            return null;
        }

        if ('1' === $raw || 'true' === $raw) {
            return true;
        }

        if ('0' === $raw || 'false' === $raw) {
            return false;
        }

        return null;
    }

    private function positiveIntQuery(Request $request, string $key): ?int
    {
        $raw = $request->query->get($key);
        if (!is_numeric($raw)) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }
}
