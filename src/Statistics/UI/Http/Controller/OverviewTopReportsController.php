<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\Overview\OverviewTopReportsFactory;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class OverviewTopReportsController extends AbstractController
{
    public function __construct(
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsScopeResolver $statisticsScopeResolver,
        private readonly ProjectionTimeSeriesQuery $timeSeriesQuery,
        private readonly OverviewTopReportsFactory $topReportsFactory,
    ) {
    }

    #[Route('/statistics/overview/top-reports', name: 'app_stats_overview_top_reports', methods: ['GET'])]
    public function frame(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $context = $this->statisticsContextFactory->create($user, $filter);
        $bounds = StatisticsPeriodResolver::resolve($filter);
        $hospitalIds = $this->statisticsScopeResolver->resolveCriteria($context)->hospitalIds;
        $scopedTotal = $this->timeSeriesQuery->countCreatedInPeriod(
            $bounds->from,
            $bounds->toExclusive,
            $hospitalIds,
        );

        return $this->render('@Statistics/dashboard/_overview_top_reports_frame.html.twig', [
            'topReportCards' => $this->topReportsFactory->build($request, $context, $scopedTotal),
        ]);
    }
}
