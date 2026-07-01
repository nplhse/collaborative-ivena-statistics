<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\TopDiagnosesQuery;
use App\Statistics\Application\TopIndicationGroupsQuery;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Translation\TranslatableMessage;

final class IndicationInsightsIndexController extends AbstractController
{
    private const int TOP_LIMIT = 25;

    private const int TOP_GROUPS_LIMIT = 10;

    public function __construct(
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly TopDiagnosesQuery $topDiagnosesQuery,
        private readonly TopIndicationGroupsQuery $topIndicationGroupsQuery,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly IndicationPickerViewModelFactory $indicationPickerViewModelFactory,
        private readonly IndicationGroupPickerViewModelFactory $groupPickerViewModelFactory,
        private readonly IndicationCompareSubjectPickerViewModelFactory $compareSubjectPickerViewModelFactory,
        private readonly StatisticsNavigationUrlBuilder $navigationUrlBuilder,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
    ) {
    }

    #[Route('/statistics/indication-insights', name: 'app_stats_indication_insights', methods: ['GET'])]
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

            return $this->redirectToRoute('app_stats_indication_insights', $publicRedirect['query']);
        }

        $context = $this->statisticsContextFactory->create($user, $filter);
        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_indication_insights',
            $user,
            $filter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
            $request,
            'app_stats_indication_insights',
            $filter,
        );
        $data = $this->topDiagnosesQuery->fetch($context, self::TOP_LIMIT);
        $total = $data['totalAllocations'];
        $topRows = [];
        $rank = 1;

        foreach ($data['rows'] as $row) {
            $count = $row['count'];
            $topRows[] = [
                'rank' => $rank,
                'label' => $row['label'],
                'count' => $count,
                'shareDisplay' => sprintf('%.1f%%', $total > 0 ? round(100 * $count / $total, 1) : 0.0),
                'url' => isset($row['indicationId'])
                    ? $this->navigationUrlBuilder->build(
                        $request,
                        'app_stats_indication_dashboard',
                        ['indicationId' => $row['indicationId']],
                    )
                    : null,
            ];
            ++$rank;
        }

        $groupsData = $this->topIndicationGroupsQuery->fetch($context, self::TOP_GROUPS_LIMIT);
        $groupsTotal = $groupsData['totalAllocations'];
        $topGroupRows = [];
        $groupRank = 1;

        foreach ($groupsData['rows'] as $row) {
            $count = $row['count'];
            $topGroupRows[] = [
                'rank' => $groupRank,
                'label' => $row['label'],
                'count' => $count,
                'shareDisplay' => sprintf('%.1f%%', $groupsTotal > 0 ? round(100 * $count / $groupsTotal, 1) : 0.0),
                'url' => $this->navigationUrlBuilder->build(
                    $request,
                    'app_stats_indication_group_dashboard',
                    ['groupId' => $row['groupId']],
                ),
            ];
            ++$groupRank;
        }

        $dataQualityReport = $this->dataQualityReportFactory->create(
            $filter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
        );

        return $this->render('@Statistics/indication_insights/index.html.twig', [
            'dataQualityReport' => $dataQualityReport,
            'topRows' => $topRows,
            'topGroupRows' => $topGroupRows,
            'indicationPicker' => $this->indicationPickerViewModelFactory->create($request),
            'comparePickerItems' => $this->compareSubjectPickerViewModelFactory->buildMenuItems(),
            'compareBaseUrl' => $this->navigationUrlBuilder->build(
                $request,
                'app_stats_indication_compare',
                [],
                [
                    StatisticsQueryKeys::INDICATION_A,
                    StatisticsQueryKeys::INDICATION_B,
                    StatisticsQueryKeys::SUBJECT_A_TYPE,
                    StatisticsQueryKeys::SUBJECT_A_ID,
                    StatisticsQueryKeys::SUBJECT_B_TYPE,
                    StatisticsQueryKeys::SUBJECT_B_ID,
                ],
            ),
            'groupPicker' => $this->groupPickerViewModelFactory->create($request),
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
        ]);
    }
}
