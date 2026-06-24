<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerDimensionCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnalysisDimensionKeyCategoryTest extends TestCase
{
    #[DataProvider('allocationsCategoryProvider')]
    public function testAllocationsExplorerCategory(AnalysisDimensionKey $dimension, ExplorerDimensionCategory $expected): void
    {
        self::assertSame(
            $expected,
            $dimension->explorerCategory(AnalysisDataSourceKey::Allocations),
        );
    }

    /**
     * @return iterable<string, array{AnalysisDimensionKey, ExplorerDimensionCategory}>
     */
    public static function allocationsCategoryProvider(): iterable
    {
        yield 'time' => [AnalysisDimensionKey::Time, ExplorerDimensionCategory::TimeAndCalendar];
        yield 'hour' => [AnalysisDimensionKey::Hour, ExplorerDimensionCategory::TimeAndCalendar];
        yield 'indication' => [AnalysisDimensionKey::Indication, ExplorerDimensionCategory::MissionAndAllocation];
        yield 'gender' => [AnalysisDimensionKey::Gender, ExplorerDimensionCategory::PatientAndDemographics];
        yield 'resus' => [AnalysisDimensionKey::Resus, ExplorerDimensionCategory::ClinicalCare];
        yield 'shock' => [AnalysisDimensionKey::Shock, ExplorerDimensionCategory::ClinicalCare];
        yield 'transport_time_bucket' => [AnalysisDimensionKey::TransportTimeBucket, ExplorerDimensionCategory::TransportAndDuration];
        yield 'hospital' => [AnalysisDimensionKey::Hospital, ExplorerDimensionCategory::HospitalAndGeography];
    }

    public function testHospitalsExplorerCategory(): void
    {
        self::assertSame(
            ExplorerDimensionCategory::HospitalProfile,
            AnalysisDimensionKey::HospitalEntity->explorerCategory(AnalysisDataSourceKey::Hospitals),
        );
        self::assertSame(
            ExplorerDimensionCategory::GeographyAndParticipation,
            AnalysisDimensionKey::HospitalState->explorerCategory(AnalysisDataSourceKey::Hospitals),
        );
    }

    public function testAllAllocationsCatalogDimensionsHaveExplorerCategory(): void
    {
        foreach (AnalysisDimensionKey::allocationsCatalog() as $dimension) {
            $category = $dimension->explorerCategory(AnalysisDataSourceKey::Allocations);

            self::assertContains(
                $category,
                new \App\Statistics\AnalysisExplorer\Application\ExplorerDimensionCatalog()->categoryOrderFor(
                    AnalysisDataSourceKey::Allocations,
                ),
                sprintf('Unexpected category for allocations dimension "%s".', $dimension->value),
            );
        }
    }

    public function testAllHospitalsCatalogDimensionsHaveExplorerCategory(): void
    {
        foreach (AnalysisDimensionKey::hospitalsCatalog() as $dimension) {
            $category = $dimension->explorerCategory(AnalysisDataSourceKey::Hospitals);

            self::assertContains(
                $category,
                new \App\Statistics\AnalysisExplorer\Application\ExplorerDimensionCatalog()->categoryOrderFor(
                    AnalysisDataSourceKey::Hospitals,
                ),
                sprintf('Unexpected category for hospitals dimension "%s".', $dimension->value),
            );
        }
    }

    public function testMetricGroupTranslationKeys(): void
    {
        self::assertSame(
            'stats.analysis_explorer.metric_group.clinical_rates',
            AnalysisMetricKey::ResusRate->explorerGroupTranslationKey(),
        );
        self::assertSame(
            'stats.analysis_explorer.metric_group.clinical_rates',
            AnalysisMetricKey::ShockRate->explorerGroupTranslationKey(),
        );
        self::assertSame(
            'stats.analysis_explorer.metric_group.shares',
            AnalysisMetricKey::PrevalenceRate->explorerGroupTranslationKey(),
        );
        self::assertSame(
            'stats.analysis_explorer.metric_group.allocations',
            AnalysisMetricKey::TotalAllocations->explorerGroupTranslationKey(),
        );
    }
}
