<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller\Insights;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\InsightCompare\DTO\InsightCompareCriteria;
use App\Statistics\Application\InsightCompare\InsightCompareFilterResolver;
use App\Statistics\Application\InsightCompare\InsightCompareReportService;
use App\Statistics\Application\InsightCompare\InsightCompareSubjectRequestParser;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightDimensionRegistry;
use App\Statistics\Application\Insights\InsightSubject;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\UI\Http\Controller\InsightCompareChartPayloadFactory;
use App\Statistics\UI\Http\Controller\InsightComparePickerViewModelFactory;
use App\Statistics\UI\Http\Controller\InsightCompareUrlHelper;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Controller\StatisticsPublicScopeRedirector;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InsightsCompareController extends AbstractController
{
    public function __construct(
        private readonly InsightCompareReportService $reportService,
        private readonly InsightCompareSubjectRequestParser $subjectRequestParser,
        private readonly InsightDimensionRegistry $registry,
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsScopeResolver $statisticsScopeResolver,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly InsightsPageChromeFactory $chromeFactory,
        private readonly InsightComparePickerViewModelFactory $comparePickerViewModelFactory,
        private readonly InsightCompareChartPayloadFactory $chartPayloadFactory,
        private readonly InsightCompareUrlHelper $compareUrlHelper,
        private readonly InsightCompareFilterResolver $comparisonFilterResolver,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        '/statistics/insights/compare',
        name: 'app_stats_insights_compare',
        methods: ['GET'],
        priority: 20,
    )]
    public function __invoke(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', new TranslatableMessage($publicRedirect['notice']->value, domain: 'statistics'));
            }

            return $this->redirectToRoute('app_stats_insights_compare', $publicRedirect['query']);
        }

        $pair = $this->subjectRequestParser->parse($request);
        if (!$pair instanceof \App\Statistics\Application\InsightCompare\DTO\InsightCompareSubjectPair) {
            $this->addFlash('error', $this->translator->trans('stats.insights.compare.error.missing_selection', [], 'statistics'));

            return $this->redirectToRoute('app_stats_insights', $request->query->all());
        }

        $subjectA = $this->registry->get($pair->dimensionA)->resolve($pair->idA);
        $subjectB = $this->registry->get($pair->dimensionB)->resolve($pair->idB);
        if (!$subjectA instanceof InsightSubject || !$subjectB instanceof InsightSubject) {
            $this->addFlash('error', $this->translator->trans('stats.insights.compare.error.missing_selection', [], 'statistics'));

            return $this->redirectToRoute('app_stats_insights', $request->query->all());
        }

        $comparisonFilter = $this->comparisonFilterResolver->resolve($request, $user, $filter);
        if ($pair->isSameSubject() && InsightCompareUrlHelper::filtersEqual($filter, $comparisonFilter)) {
            $this->addFlash('error', $this->translator->trans('stats.insights.compare.error.same_subject', [], 'statistics'));

            return $this->redirectToRoute('app_stats_insights_show', array_merge(
                $request->query->all(),
                ['dimension' => $subjectA->dimension->value, 'id' => $subjectA->id],
            ));
        }

        if ($subjectA->population->isEmpty() || $subjectB->population->isEmpty()) {
            $this->addFlash('error', $this->translator->trans('stats.insights.compare.error.empty_group', [], 'statistics'));

            return $this->redirectToRoute('app_stats_insights_show', array_merge(
                $request->query->all(),
                ['dimension' => $subjectA->dimension->value, 'id' => $subjectA->id],
            ));
        }

        $contextA = $this->statisticsContextFactory->create($user, $filter);
        $contextB = $this->statisticsContextFactory->create($user, $comparisonFilter);
        $scopeA = $this->statisticsScopeResolver->resolveCriteria($contextA);
        $scopeB = $this->statisticsScopeResolver->resolveCriteria($contextB);
        $periodA = StatisticsPeriodResolver::resolve($filter);
        $periodB = StatisticsPeriodResolver::resolve($comparisonFilter);

        $chrome = $this->chromeFactory->templateVars($request, 'app_stats_insights_compare', $user, $filter);
        $comparisonChrome = $this->chromeFactory->templateVars($request, 'app_stats_insights_compare', $user, $comparisonFilter);
        $filterLabelA = $chrome['statisticsHeadingScope'].' · '.$chrome['statisticsHeadingPeriod'];
        $filterLabelB = $comparisonChrome['statisticsHeadingScope'].' · '.$comparisonChrome['statisticsHeadingPeriod'];

        $disabledInsightIds = array_values(array_unique(array_merge(
            $this->registry->get($subjectA->dimension)->disabledInsightIds(),
            $this->registry->get($subjectB->dimension)->disabledInsightIds(),
        )));

        $report = $this->reportService->build(
            new InsightCompareCriteria(
                $subjectA,
                $subjectB,
                $scopeA,
                $periodA,
                $scopeB,
                $periodB,
            ),
            $filterLabelA,
            $filterLabelB,
            $disabledInsightIds,
        );

        $sameScopePeriod = InsightCompareUrlHelper::filtersEqual($filter, $comparisonFilter);
        $overlapIds = [];
        if ($sameScopePeriod && $subjectA->population->column === $subjectB->population->column) {
            $overlapIds = array_values(array_intersect($subjectA->population->ids, $subjectB->population->ids));
        }

        $comparePicker = $this->comparePickerViewModelFactory->create(
            $request,
            $subjectA,
            $subjectB,
            $comparisonFilter,
            $subjectA->label,
            $report->header->dimensionLabelA.' · '.$filterLabelA,
        );

        return $this->render('@Statistics/insights/compare.html.twig', array_merge(
            $chrome,
            [
                'report' => $report,
                'chartPayload' => $this->chartPayloadFactory->create($report),
                'comparePicker' => $comparePicker,
                'insightDashboardUrlA' => $this->compareUrlHelper->buildDashboardUrl($request, $subjectA),
                'insightDashboardUrlB' => $this->compareUrlHelper->buildDashboardUrl($request, $subjectB),
                'compareSwapUrl' => $this->compareUrlHelper->buildSwapUrl(
                    $request,
                    $subjectA,
                    $subjectB,
                    $filter,
                    $comparisonFilter,
                ),
                'hasOverlappingPopulations' => [] !== $overlapIds,
                'statsShowCompareEditButton' => true,
                'statsInsightsCompareDisableUrl' => $this->compareUrlHelper->buildDashboardUrl($request, $subjectA),
            ],
        ));
    }

    #[Route(
        '/statistics/insights/{dimension}/compare',
        name: 'app_stats_insights_compare_legacy',
        requirements: ['dimension' => 'indications|indication-groups|specialities|assignments|departments|occasions|infections|secondary-transports'],
        methods: ['GET'],
        priority: 10,
    )]
    public function legacyDimension(Request $request, string $dimension): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $dimensionKey = InsightDimensionKey::tryFrom($dimension);

        return $this->redirectToRoute(
            'app_stats_insights_compare',
            $this->subjectRequestParser->canonicalizeQuery($request->query->all(), $dimensionKey),
        );
    }
}
