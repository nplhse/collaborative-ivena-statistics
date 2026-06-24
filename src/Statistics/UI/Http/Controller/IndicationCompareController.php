<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\IndicationCompare\DTO\IndicationCompareCriteria;
use App\Statistics\Application\IndicationCompare\IndicationCompareReportService;
use App\Statistics\Application\IndicationCompare\IndicationCompareSubjectRequestParser;
use App\Statistics\Application\IndicationDashboard\IndicationSubjectResolver;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class IndicationCompareController extends AbstractController
{
    public function __construct(
        private readonly IndicationCompareReportService $reportService,
        private readonly IndicationCompareSubjectRequestParser $subjectRequestParser,
        private readonly IndicationSubjectResolver $subjectResolver,
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsScopeResolver $statisticsScopeResolver,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly IndicationComparePickerViewModelFactory $comparePickerViewModelFactory,
        private readonly IndicationCompareChartPayloadFactory $chartPayloadFactory,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
        private readonly IndicationCompareUrlHelper $compareUrlHelper,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/statistics/indication/compare', name: 'app_stats_indication_compare', methods: ['GET'])]
    public function __invoke(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', $publicRedirect['notice']->value);
            }

            return $this->redirectToRoute('app_stats_indication_compare', $publicRedirect['query']);
        }

        $subjectPair = $this->subjectRequestParser->parse($request);

        if (!$subjectPair instanceof \App\Statistics\Application\IndicationCompare\DTO\IndicationCompareSubjectPair) {
            $this->addFlash('error', $this->translator->trans('stats.indication.compare.error.missing_selection'));

            return $this->redirectToRoute('app_stats_indication_insights', $request->query->all());
        }

        if ($subjectPair->isSameSubject()) {
            $this->addFlash('error', $this->translator->trans('stats.indication.compare.error.same_indication'));

            return $this->redirectToRoute('app_stats_indication_insights', $request->query->all());
        }

        $subjectA = $this->subjectResolver->resolve($subjectPair->typeA, $subjectPair->idA);
        $subjectB = $this->subjectResolver->resolve($subjectPair->typeB, $subjectPair->idB);

        if (!$subjectA instanceof \App\Statistics\Application\IndicationDashboard\IndicationSubject || !$subjectB instanceof \App\Statistics\Application\IndicationDashboard\IndicationSubject) {
            throw $this->createNotFoundException('Compare subject not found.');
        }

        if ([] === $subjectA->indicationIds || [] === $subjectB->indicationIds) {
            $this->addFlash('error', $this->translator->trans('stats.indication.compare.error.empty_group'));

            return $this->redirectToRoute('app_stats_indication_insights', $request->query->all());
        }

        $context = $this->statisticsContextFactory->create($user, $filter);
        $scope = $this->statisticsScopeResolver->resolveCriteria($context);
        $period = StatisticsPeriodResolver::resolve($filter);

        $report = $this->reportService->build(new IndicationCompareCriteria(
            $subjectA,
            $subjectB,
            $scope,
            $period,
        ));

        $overlapIds = array_values(array_intersect($subjectA->indicationIds, $subjectB->indicationIds));

        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_indication_compare',
            $user,
            $filter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
            $request,
            'app_stats_indication_compare',
            $filter,
        );
        $dataQualityReport = $this->dataQualityReportFactory->create(
            $filter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
        );

        return $this->render('@Statistics/indication_compare/index.html.twig', [
            'report' => $report,
            'chartPayload' => $this->chartPayloadFactory->create($report),
            'comparePicker' => $this->comparePickerViewModelFactory->create($request, $subjectA, $subjectB),
            'indicationDashboardUrlA' => $this->compareUrlHelper->buildDashboardUrl($request, $subjectA),
            'indicationDashboardUrlB' => $this->compareUrlHelper->buildDashboardUrl($request, $subjectB),
            'hasOverlappingIndications' => [] !== $overlapIds,
            'dataQualityReport' => $dataQualityReport,
            'statisticsFilter' => $pageViewModel->filter,
            'statsScopeUrls' => $pageViewModel->scopeUrls,
            'statsHospitalUrls' => $pageViewModel->hospitalUrls,
            'cohortScopeChoices' => $pageViewModel->cohortScopeChoices,
            'statsCohortDropdownSelectedName' => $pageViewModel->cohortDropdownSelectedName,
            'statsScopePrimaryMenu' => $pageViewModel->scopePrimaryMenu,
            'statsScopeSecondaryMenu' => $pageViewModel->scopeSecondaryMenu,
            'statsShowScopeSecondaryPicker' => $pageViewModel->showScopeSecondaryPicker,
            'statsScopePrimaryDropdownLabel' => $pageViewModel->scopePrimaryDropdownLabel,
            'statsScopeSecondaryDropdownLabel' => $pageViewModel->scopeSecondaryDropdownLabel,
            'statsPeriodUrls' => $pageViewModel->periodUrls,
            'accessibleHospitals' => $pageViewModel->accessibleHospitals,
            'statsHospitalDropdownSelectedName' => $pageViewModel->hospitalDropdownSelectedName,
            'isLoggedIn' => $pageViewModel->isLoggedIn,
            'statisticsHeadingScope' => $pageViewModel->headingScope,
            'statisticsHeadingPeriod' => $overviewPeriodViewModel->headingLabel,
            'overviewPeriodViewModel' => $overviewPeriodViewModel,
            'statsUseOverviewPeriodControls' => true,
            'statsShowCompareEditButton' => true,
        ]);
    }
}
