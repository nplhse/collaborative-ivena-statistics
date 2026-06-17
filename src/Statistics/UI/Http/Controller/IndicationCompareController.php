<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\IndicationCompare\DTO\IndicationCompareCriteria;
use App\Statistics\Application\IndicationCompare\IndicationCompareReportService;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
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
        private readonly IndicationNormalizedRepository $indicationRepository,
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsScopeResolver $statisticsScopeResolver,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly StatisticsFilterDrawerStateFactory $statisticsFilterDrawerStateFactory,
        private readonly IndicationComparePickerViewModelFactory $comparePickerViewModelFactory,
        private readonly IndicationCompareChartPayloadFactory $chartPayloadFactory,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
        private readonly StatisticsNavigationUrlBuilder $navigationUrlBuilder,
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

        $indicationIdA = $this->parseIndicationId($request->query->get(StatisticsQueryKeys::INDICATION_A));
        $indicationIdB = $this->parseIndicationId($request->query->get(StatisticsQueryKeys::INDICATION_B));

        if (null === $indicationIdA || null === $indicationIdB) {
            $this->addFlash('error', $this->translator->trans('stats.indication.compare.error.missing_selection'));

            return $this->redirectToRoute('app_stats_indication_insights', $request->query->all());
        }

        if ($indicationIdA === $indicationIdB) {
            $this->addFlash('error', $this->translator->trans('stats.indication.compare.error.same_indication'));

            return $this->redirectToRoute('app_stats_indication_insights', $request->query->all());
        }

        $indicationA = $this->indicationRepository->find($indicationIdA);
        $indicationB = $this->indicationRepository->find($indicationIdB);
        if (null === $indicationA || null === $indicationB) {
            throw $this->createNotFoundException('Indication not found.');
        }

        $context = $this->statisticsContextFactory->create($user, $filter);
        $scope = $this->statisticsScopeResolver->resolveCriteria($context);
        $period = StatisticsPeriodResolver::resolve($filter);

        $labelA = $this->indicationRepository->getDatalistLabelById($indicationIdA) ?? $indicationA->getName() ?? '';
        $labelB = $this->indicationRepository->getDatalistLabelById($indicationIdB) ?? $indicationB->getName() ?? '';

        $report = $this->reportService->build(new IndicationCompareCriteria(
            $indicationIdA,
            $indicationIdB,
            $labelA,
            $labelB,
            $scope,
            $period,
        ));

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
        $drawerState = $this->statisticsFilterDrawerStateFactory->fromRequest($request);

        $dataQualityReport = $this->dataQualityReportFactory->create(
            $filter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
        );

        return $this->render('@Statistics/indication_compare/index.html.twig', [
            'report' => $report,
            'chartPayload' => $this->chartPayloadFactory->create($report),
            'comparePicker' => $this->comparePickerViewModelFactory->create($request, $indicationIdA, $indicationIdB),
            'indicationDashboardUrlA' => $this->navigationUrlBuilder->build(
                $request,
                'app_stats_indication_dashboard',
                ['indicationId' => $indicationIdA],
            ),
            'indicationDashboardUrlB' => $this->navigationUrlBuilder->build(
                $request,
                'app_stats_indication_dashboard',
                ['indicationId' => $indicationIdB],
            ),
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
            'statsFilterDrawerValues' => $drawerState['values'],
            'statsActiveFilterCount' => $drawerState['activeCount'],
            'statsFilterDrawerResetUrl' => $this->generateUrl('app_stats_indication_compare', [
                StatisticsQueryKeys::INDICATION_A => $indicationIdA,
                StatisticsQueryKeys::INDICATION_B => $indicationIdB,
            ]),
            'statsShowCompareEditButton' => true,
        ]);
    }

    private function parseIndicationId(mixed $value): ?int
    {
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }

        $stringValue = (string) $value;
        if ('' === $stringValue || !ctype_digit($stringValue)) {
            return null;
        }

        return (int) $stringValue;
    }
}
