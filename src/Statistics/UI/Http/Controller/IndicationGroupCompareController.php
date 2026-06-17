<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\IndicationDashboard\IndicationSubjectResolver;
use App\Statistics\Application\IndicationGroup\IndicationGroupMemberCompareService;
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

final class IndicationGroupCompareController extends AbstractController
{
    public function __construct(
        private readonly IndicationSubjectResolver $subjectResolver,
        private readonly IndicationGroupMemberCompareService $memberCompareService,
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsScopeResolver $statisticsScopeResolver,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    #[Route('/statistics/indication-group/{groupId}/compare', name: 'app_stats_indication_group_compare', requirements: ['groupId' => '\d+'], methods: ['GET'])]
    public function __invoke(
        Request $request,
        int $groupId,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $subject = $this->subjectResolver->resolveGroup($groupId);
        if (!$subject instanceof \App\Statistics\Application\IndicationDashboard\IndicationSubject) {
            throw $this->createNotFoundException('Indication group not found.');
        }

        $context = $this->statisticsContextFactory->create($user, $filter);
        $scope = $this->statisticsScopeResolver->resolveCriteria($context);
        $period = StatisticsPeriodResolver::resolve($filter);

        $memberRows = $this->memberCompareService->buildMemberRows($subject, $scope, $period);

        $pairwiseLinks = [];
        $ids = array_column($memberRows, 'indicationId');
        $counter = \count($ids);
        for ($i = 0; $i < $counter; ++$i) {
            for ($j = $i + 1; $j < \count($ids); ++$j) {
                $pairwiseLinks[] = [
                    'url' => $this->navigationUrlBuilder->build($request, 'app_stats_indication_compare', [
                        StatisticsQueryKeys::INDICATION_A => $ids[$i],
                        StatisticsQueryKeys::INDICATION_B => $ids[$j],
                    ]),
                    'labelA' => $memberRows[$i]['label'],
                    'labelB' => $memberRows[$j]['label'],
                ];
            }
        }

        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_indication_group_compare',
            $user,
            $filter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
            $request,
            'app_stats_indication_group_compare',
            $filter,
        );

        return $this->render('@Statistics/indication_group/compare.html.twig', [
            'groupName' => $subject->label,
            'groupId' => $groupId,
            'memberRows' => $memberRows,
            'pairwiseLinks' => $pairwiseLinks,
            'statisticsHeadingScope' => $pageViewModel->headingScope,
            'statisticsHeadingPeriod' => $overviewPeriodViewModel->headingLabel,
        ]);
    }
}
