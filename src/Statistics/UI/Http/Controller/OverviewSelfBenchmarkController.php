<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\Overview\OverviewKpiPresentationFactory;
use App\Statistics\Application\Overview\OverviewSelfBenchmarkFrameFactory;
use App\Statistics\Application\StatisticsContextFactory;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class OverviewSelfBenchmarkController extends AbstractController
{
    public function __construct(
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly OverviewSelfBenchmarkFrameFactory $selfBenchmarkFrameFactory,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly OverviewKpiPresentationFactory $overviewKpiPresentationFactory,
    ) {
    }

    #[Route('/statistics/overview/self-benchmark', name: 'app_stats_overview_self_benchmark', methods: ['GET'])]
    public function frame(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $context = $this->statisticsContextFactory->create($user, $filter);
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create($request, 'app_stats_dashboard', $filter);
        $selfBenchmarkFrame = $this->selfBenchmarkFrameFactory->build(
            $request,
            $context,
            $overviewPeriodViewModel->headingLabel,
        );

        return $this->render('@Statistics/dashboard/_overview_self_benchmark_frame.html.twig', [
            'selfBenchmarkFrame' => $selfBenchmarkFrame,
            'overviewKpiMetricLabelKeys' => $this->overviewKpiPresentationFactory->metricLabelKeys($filter),
        ]);
    }
}
