<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerChoiceGrouper;
use App\Statistics\AnalysisExplorer\Application\ExplorerDimensionCatalog;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditChoicePresenter;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricCatalog;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricProfileRegistry;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExplorerEditChoicePresenterTest extends TestCase
{
    public function testGroupedDimensionChoicesUseCategoryOrderAndAlphabeticalLabels(): void
    {
        $presenter = $this->presenter();

        $grouped = $presenter->groupedDimensionChoices(
            [
                AnalysisDimensionKey::Hospital,
                AnalysisDimensionKey::Hour,
                AnalysisDimensionKey::Resus,
                AnalysisDimensionKey::Indication,
            ],
            AnalysisDataSourceKey::Allocations,
            'en',
        );

        self::assertSame(
            ['Time and calendar', 'Mission and allocation', 'Clinical care', 'Hospital and geography'],
            array_keys($grouped),
        );
        self::assertSame('hour', $grouped['Time and calendar']['Hour']);
        self::assertSame('indication', $grouped['Mission and allocation']['Indication']);
        self::assertSame('resus', $grouped['Clinical care']['Resuscitation room required']);
        self::assertSame('hospital', $grouped['Hospital and geography']['Hospital']);
    }

    public function testGroupedMetricChoicesSeparateClinicalRatesFromCounts(): void
    {
        $presenter = $this->presenter();

        $grouped = $presenter->groupedMetricChoices(
            [
                AnalysisMetricKey::ResusRate,
                AnalysisMetricKey::AllocationCount,
                AnalysisMetricKey::PrevalenceRate,
            ],
            AnalysisDataSourceKey::Allocations,
            'en',
        );

        self::assertSame(['Counts', 'Clinical rates', 'Shares and distribution'], array_keys($grouped));
        self::assertArrayHasKey('Resuscitation room rate', $grouped['Clinical rates']);
    }

    public function testGroupedMetricChoicesForHospitalsFollowConfiguredGroupOrder(): void
    {
        $presenter = $this->presenter();

        $grouped = $presenter->groupedMetricChoices(
            [
                AnalysisMetricKey::TransportTimePerHospitalDistribution,
                AnalysisMetricKey::HospitalCount,
                AnalysisMetricKey::SumBeds,
                AnalysisMetricKey::TotalAllocations,
            ],
            AnalysisDataSourceKey::Hospitals,
            'en',
        );

        self::assertSame(
            ['Counts', 'Beds', 'Allocations', 'Transport times'],
            array_keys($grouped),
        );
    }

    private function presenter(): ExplorerEditChoicePresenter
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'stats.analysis_explorer.dimension_group.time_and_calendar' => 'Time and calendar',
                'stats.analysis_explorer.dimension_group.mission_and_allocation' => 'Mission and allocation',
                'stats.analysis_explorer.dimension_group.clinical_care' => 'Clinical care',
                'stats.analysis_explorer.dimension_group.hospital_and_geography' => 'Hospital and geography',
                'stats.analysis_explorer.metric_group.counts' => 'Counts',
                'stats.analysis_explorer.metric_group.clinical_rates' => 'Clinical rates',
                'stats.analysis_explorer.metric_group.shares' => 'Shares and distribution',
                'stats.analysis_explorer.metric_group.beds' => 'Beds',
                'stats.analysis_explorer.metric_group.allocations' => 'Allocations',
                'stats.analysis_explorer.metric_group.transport_times' => 'Transport times',
                'stats.analysis_explorer.dimension.hour' => 'Hour',
                'stats.analysis_explorer.dimension.indication' => 'Indication',
                'stats.analysis_explorer.dimension.resus' => 'Resuscitation room required',
                'stats.analysis_explorer.dimension.hospital' => 'Hospital',
                'stats.analysis_explorer.metric.allocation_count' => 'Allocations',
                'stats.analysis_explorer.metric.resus_rate' => 'Resuscitation room rate',
                'stats.analysis_explorer.metric.prevalence_rate' => 'Share within category (%)',
                'stats.analysis_explorer.metric.hospital_count' => 'Hospitals',
                'stats.analysis_explorer.metric.sum_beds' => 'Total beds',
                'stats.analysis_explorer.metric.total_allocations' => 'Total allocations',
                'stats.analysis_explorer.metric_profile.transport_time_per_hospital_distribution' => 'Transport time per hospital distribution (box plot)',
                default => $id,
            },
        );

        return new ExplorerEditChoicePresenter(
            new ExplorerChoiceGrouper($translator),
            new ExplorerDimensionCatalog(),
            new ExplorerMetricCatalog(new MetricRegistry()),
            new ExplorerMetricProfileRegistry(),
            $translator,
        );
    }
}
