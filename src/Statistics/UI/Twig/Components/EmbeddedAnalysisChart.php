<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use App\Statistics\AnalysisExplorer\Application\AnalysisExecution;
use App\Statistics\AnalysisExplorer\Application\ExplorerTitleFactory;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerQueryKeys;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'Statistics:EmbeddedAnalysisChart', template: '@Statistics/components/EmbeddedAnalysisChart.html.twig')]
final class EmbeddedAnalysisChart
{
    /** @psalm-suppress PropertyNotSetInConstructor Consumed by EmbeddedAnalysisChart.html.twig. */
    public StatisticsFilter $statisticsFilter;

    public string $titleKey = 'stats.indication.section.age_groups';

    public string $linkLabelKey = 'stats.nav.overview_age_groups_to_analysis';

    public string $testId = 'stats-overview-age-groups';

    public string $linkTestId = 'stats-cross-nav-overview-age-groups';

    public string $explorerSlug = 'age-group-distribution';

    /** @var array{hasChart: bool, chartSpecs: array<string, mixed>, defaultChartType: string, explorerUrl: string}|null */
    private ?array $view = null;

    public function __construct(
        private readonly AnalysisExecution $analysisExecution,
        private readonly ExplorerTitleFactory $titleFactory,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Security $security,
    ) {
    }

    /**
     * @return array{hasChart: bool, chartSpecs: array<string, mixed>, defaultChartType: string, explorerUrl: string}
     */
    public function view(): array
    {
        if (null !== $this->view) {
            return $this->view;
        }

        $user = $this->security->getUser();
        $execution = $this->analysisExecution->execute(
            $this->config()->withStatisticsFilter($this->statisticsFilter),
            $user instanceof User ? $user : null,
        );

        return $this->view = [
            'hasChart' => $execution->hasChart,
            'chartSpecs' => $execution->chartSpecs,
            'defaultChartType' => $execution->defaultChartType,
            'explorerUrl' => $this->urlGenerator->generate('app_stats_analysis_explorer_view', [
                'view' => $this->explorerSlug,
                ExplorerQueryKeys::USE_PAGE_SCOPE => '1',
                ...$this->filterQueryParams($this->statisticsFilter),
            ]),
        ];
    }

    private function config(): AnalysisViewConfig
    {
        $rowAxis = new AnalysisAxisRef(AnalysisDimensionKey::AgeGroup, AnalysisDimensionGrain::Total);

        return new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: $rowAxis,
            columnAxis: null,
            statisticsFilter: $this->statisticsFilter,
            presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
            title: $this->titleFactory->titleForAxes($rowAxis, null),
        );
    }

    /**
     * @return array<string, scalar>
     */
    private function filterQueryParams(StatisticsFilter $filter): array
    {
        return array_filter(
            [
                StatisticsQueryKeys::SCOPE => $filter->scope->value,
                StatisticsQueryKeys::HOSPITAL => $filter->hospitalId,
                StatisticsQueryKeys::STATE => $filter->stateId,
                StatisticsQueryKeys::DISPATCH_AREA => $filter->dispatchAreaId,
                StatisticsQueryKeys::COHORT => $filter->cohortType?->value(),
                StatisticsQueryKeys::PERIOD => $filter->period->value,
                StatisticsQueryKeys::YEAR => $filter->referenceYear,
                StatisticsQueryKeys::MONTH => $filter->referenceMonth,
                StatisticsQueryKeys::QUARTER => $filter->referenceQuarter,
            ],
            static fn (mixed $value): bool => null !== $value && '' !== $value,
        );
    }
}
