<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerTitleFactory;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExplorerTitleFactoryTest extends TestCase
{
    public function testTitleForGenderTotalUsesSummedTitle(): void
    {
        $factory = new ExplorerTitleFactory($this->translator([
            'stats.analysis_explorer.allocations_by_dimension' => 'Allocations by {dimension}',
            'stats.analysis_explorer.dimension.gender' => 'gender',
        ]));

        self::assertSame(
            'Allocations by gender',
            $factory->titleForAxes(AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender), null),
        );
    }

    public function testTitleForGenderMonthUsesOverTimeTitle(): void
    {
        $factory = new ExplorerTitleFactory($this->translator([
            'stats.analysis_explorer.allocations_by_dimension_over_time' => 'Allocations by {dimension} over time',
            'stats.analysis_explorer.dimension.gender' => 'gender',
        ]));

        self::assertSame(
            'Allocations by gender over time',
            $factory->titleForAxes(
                AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            ),
        );
    }

    public function testTitleForAgeGroupByMonthUsesCrossTabTitle(): void
    {
        $factory = new ExplorerTitleFactory($this->translator([
            'stats.analysis_explorer.allocations_by_dimension_by_temporal' => '{dimension} by {temporal}',
            'stats.analysis_explorer.dimension.age_group' => 'age group',
            'stats.analysis_explorer.dimension.month' => 'month',
        ]));

        self::assertSame(
            'age group by month',
            $factory->titleForAxes(
                AnalysisAxisRef::breakdown(AnalysisDimensionKey::AgeGroup),
                AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            ),
        );
    }

    public function testTitleForConfigFollowsTheAnalysisShape(): void
    {
        $factory = new ExplorerTitleFactory($this->translator([
            'stats.analysis_explorer.allocations_over_time' => 'over time',
            'stats.analysis_explorer.allocations_by_dimension' => 'by {dimension}',
            'stats.analysis_explorer.allocations_cross_tab' => '{rows} x {columns}',
            'stats.analysis_explorer.dimension.gender' => 'gender',
            'stats.analysis_explorer.dimension.urgency' => 'urgency',
        ]));

        self::assertSame('over time', $factory->titleForConfig($this->config(
            AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            null,
        )));
        self::assertSame('by gender', $factory->titleForConfig($this->config(
            AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            null,
        )));
        self::assertSame('gender x urgency', $factory->titleForConfig($this->config(
            AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency),
        )));
    }

    public function testHospitalCrossTabNamesHospitals(): void
    {
        $factory = new ExplorerTitleFactory($this->translator([
            'stats.analysis_explorer.hospitals_cross_tab' => 'Hospitals: {rows} × {columns}',
            'stats.analysis_explorer.dimension.hospital_location' => 'location',
            'stats.analysis_explorer.dimension.hospital_tier' => 'care tier',
        ]));

        self::assertSame(
            'Hospitals: location × care tier',
            $factory->titleForAxes(
                AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalLocation),
                AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalTier),
                AnalysisDataSourceKey::Hospitals,
            ),
        );
    }

    private function config(AnalysisAxisRef $rowAxis, ?AnalysisAxisRef $columnAxis): AnalysisViewConfig
    {
        return new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: $rowAxis,
            columnAxis: $columnAxis,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
            title: 'Ignored',
        );
    }

    /**
     * @param array<string, string> $map
     */
    private function translator(array $map): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []) use ($map): string {
                $template = $map[$id] ?? $id;

                return strtr($template, array_combine(
                    array_map(static fn (string $key): string => '{'.$key.'}', array_keys($parameters)),
                    array_values($parameters),
                ));
            },
        );

        return $translator;
    }
}
