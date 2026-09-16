<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller\Insights;

use App\Allocation\Application\Explore\ExploreShowUrlResolver;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\IndicationDashboard\IndicationDashboardService;
use App\Statistics\Application\IndicationDashboard\IndicationSubjectResolver;
use App\Statistics\Application\IndicationGroup\IndicationGroupMemberCompareService;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightDimensionRegistry;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Application\TimeSeries\TimeSeriesGrainResolver;
use App\Statistics\UI\Http\Controller\IndicationComparePickerViewModelFactory;
use App\Statistics\UI\Http\Controller\IndicationDashboardChartPayloadFactory;
use App\Statistics\UI\Http\Controller\IndicationGroupComparePickerViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Controller\StatisticsPublicScopeRedirector;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Translation\TranslatableMessage;

final class InsightsDashboardController extends AbstractController
{
    public function __construct(
        private readonly InsightDimensionRegistry $registry,
        private readonly IndicationDashboardService $dashboardService,
        private readonly IndicationSubjectResolver $subjectResolver,
        private readonly IndicationGroupMemberCompareService $memberCompareService,
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsScopeResolver $statisticsScopeResolver,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly InsightsPageChromeFactory $chromeFactory,
        private readonly IndicationDashboardChartPayloadFactory $chartPayloadFactory,
        private readonly IndicationComparePickerViewModelFactory $comparePickerViewModelFactory,
        private readonly IndicationGroupComparePickerViewModelFactory $groupComparePickerViewModelFactory,
        private readonly ExploreShowUrlResolver $exploreShowUrlResolver,
        private readonly StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    #[Route(
        '/statistics/insights/{dimension}/{id}',
        name: 'app_stats_insights_show',
        requirements: [
            'dimension' => 'indications|indication-groups|specialities|assignments|departments|occasions|infections|secondary-transports',
            'id' => '\d+',
        ],
        methods: ['GET'],
    )]
    public function __invoke(
        Request $request,
        string $dimension,
        int $id,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $dimensionKey = InsightDimensionKey::tryFrom($dimension);
        if (!$dimensionKey instanceof InsightDimensionKey) {
            throw $this->createNotFoundException('Insight dimension not found.');
        }

        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', new TranslatableMessage($publicRedirect['notice']->value, domain: 'statistics'));
            }

            return $this->redirectToRoute('app_stats_insights_show', array_merge(
                $publicRedirect['query'],
                ['dimension' => $dimensionKey->value, 'id' => $id],
            ));
        }

        $provider = $this->registry->get($dimensionKey);
        $subject = $provider->resolve($id);
        if (!$subject instanceof \App\Statistics\Application\Insights\InsightSubject) {
            throw $this->createNotFoundException('Insight value not found.');
        }

        $context = $this->statisticsContextFactory->create($user, $filter);
        $scope = $this->statisticsScopeResolver->resolveCriteria($context);
        $period = StatisticsPeriodResolver::resolve($filter);

        $result = $this->dashboardService->buildForInsight(
            $subject,
            $scope,
            $period,
            TimeSeriesGrainResolver::resolve($filter->period),
            $provider->disabledInsightIds(),
        );

        if (InsightDimensionKey::IndicationGroups === $dimensionKey && !$result instanceof \App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardResult) {
            return $this->render('@Statistics/indication_group/empty.html.twig', [
                'groupName' => $subject->label,
            ]);
        }

        if (!$result instanceof \App\Statistics\Application\IndicationDashboard\DTO\IndicationDashboardResult) {
            throw $this->createNotFoundException('Insight value not found.');
        }

        $memberRows = [];
        $comparePresets = [];
        if (InsightDimensionKey::IndicationGroups === $dimensionKey) {
            $indicationSubject = $this->subjectResolver->resolveGroup($id);
            if ($indicationSubject instanceof \App\Statistics\Application\IndicationDashboard\IndicationSubject) {
                $memberRows = $this->memberCompareService->buildMemberRows($indicationSubject, $scope, $period);
                $compareMemberRows = $this->memberCompareService->buildComparePickerRows($indicationSubject, $memberRows);
                $memberRows = array_map(function (array $row) use ($request): array {
                    $row['insightUrl'] = $this->navigationUrlBuilder->build($request, 'app_stats_insights_show', [
                        'dimension' => InsightDimensionKey::Indications->value,
                        'id' => $row['indicationId'],
                    ]);

                    return $row;
                }, $memberRows);
                $comparePresets = \count($compareMemberRows) >= 2
                    ? $this->groupComparePickerViewModelFactory->createPresets($compareMemberRows)
                    : [];
            }
        }

        $catalogClass = $provider->entityFqcn();
        $catalogUrl = null !== $subject->publicId && '' !== $subject->publicId
            ? $this->exploreShowUrlResolver->resolveUrlForClass($catalogClass, $subject->publicId)
            : null;
        $topListKey = $dimensionKey->topListKey();

        $indicationIdForQuality = InsightDimensionKey::Indications === $dimensionKey ? $id : null;

        return $this->render('@Statistics/insights/dashboard.html.twig', array_merge(
            $this->chromeFactory->templateVars($request, 'app_stats_insights_show', $user, $filter, $indicationIdForQuality),
            [
                'dashboard' => $result,
                'chartPayload' => $this->chartPayloadFactory->create($result),
                'dimensionKey' => $dimensionKey,
                'provider' => $provider,
                'subject' => $subject,
                'memberRows' => $memberRows,
                'comparePresets' => $comparePresets,
                'comparePicker' => $provider->supportsCompare()
                    ? $this->comparePickerViewModelFactory->create($request, $subject)
                    : null,
                'statsShowCompareLaunchButton' => $provider->supportsCompare(),
                'statsIndicationCatalogUrl' => $catalogUrl,
                'statsIndicationTopListUrl' => null !== $topListKey
                    ? $this->navigationUrlBuilder->build($request, 'app_stats_top_lists_show', ['report' => $topListKey])
                    : null,
                'isochroneOriginMapUrl' => $this->navigationUrlBuilder->build(
                    $request,
                    'app_stats_isochrone_origin_map',
                    ['indicationId' => null, 'groupId' => null],
                ),
            ],
        ));
    }
}
