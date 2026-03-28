<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Panel\Distribution\ApexChartsDistributionOptionsFactory;
use App\Statistics\Application\Panel\Distribution\DistributionGroupedChartMode;
use App\Statistics\Application\Panel\Distribution\DistributionPanelBuilder;
use App\Statistics\Infrastructure\Query\AllocationStatsDistributionQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/statistics/distribution', name: 'app_stats_distribution', methods: ['GET'])]
final class DistributionPanelController extends AbstractController
{
    public function __construct(
        private readonly DistributionPanelBuilder $panelBuilder,
        private readonly ApexChartsDistributionOptionsFactory $apexOptionsFactory,
        private readonly AllocationStatsDistributionQuery $distributionQuery,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $primary = $request->query->getString('primary', 'urgency');
        $group = $request->query->get('group');
        $group = \is_string($group) && '' !== $group ? $group : null;

        if (!\in_array($primary, AllocationStatsDistributionQuery::primaryDimensions(), true)) {
            throw new BadRequestHttpException('Invalid primary dimension.');
        }

        if (null !== $group && !\in_array($group, AllocationStatsDistributionQuery::groupDimensions(), true)) {
            throw new BadRequestHttpException('Invalid group dimension.');
        }

        $groupedChartMode = null;
        if (null !== $group) {
            $groupedChartMode = DistributionGroupedChartMode::tryFromQuery($request->query->get('chart'))
                ?? DistributionGroupedChartMode::AbsoluteGrouped;
        }

        $projectionRowCount = $this->distributionQuery->countRows();

        $view = $this->panelBuilder->build($primary, $group);
        $chartOptions = $this->apexOptionsFactory->build($view, 320, $groupedChartMode);

        $chartUsesPercentYAxis = null !== $group
            && DistributionGroupedChartMode::PercentStacked === $groupedChartMode;

        return $this->render('@Statistics/distribution/index.html.twig', [
            'projectionRowCount' => $projectionRowCount,
            'panelView' => $view,
            'chartOptions' => $chartOptions,
            'chartMode' => $groupedChartMode instanceof DistributionGroupedChartMode
                ? $groupedChartMode->value
                : DistributionGroupedChartMode::AbsoluteGrouped->value,
            'chartUsesPercentYAxis' => $chartUsesPercentYAxis,
            'primary' => $primary,
            'group' => $group,
            'primaryDimensions' => AllocationStatsDistributionQuery::primaryDimensions(),
            'groupDimensions' => AllocationStatsDistributionQuery::groupDimensions(),
        ]);
    }
}
