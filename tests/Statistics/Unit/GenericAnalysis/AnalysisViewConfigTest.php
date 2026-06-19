<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewConfig;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalPopulationMode;
use PHPUnit\Framework\TestCase;

final class AnalysisViewConfigTest extends TestCase
{
    public function testRoundTripIncludesDataSourceAndPopulationMode(): void
    {
        $config = new AnalysisViewConfig(
            primaryDimensionKey: 'hospital_tier',
            secondaryDimensionKey: null,
            metricKeys: ['hospital_count', 'percent_of_total'],
            visualMetricKey: 'hospital_count',
            configVersion: 3,
            dataSource: AnalysisDataSource::Hospitals,
            hospitalPopulationMode: HospitalPopulationMode::Participating->value,
        );

        $restored = AnalysisViewConfig::fromArray($config->toArray());

        self::assertSame(AnalysisDataSource::Hospitals, $restored->dataSource);
        self::assertSame(HospitalPopulationMode::Participating, $restored->resolvedHospitalPopulationMode());
        self::assertSame(3, $restored->configVersion);
        self::assertSame(['hospital_count', 'percent_of_total'], $restored->resolvedMetricKeys());
    }

    public function testLegacyConfigDefaultsToAllocations(): void
    {
        $restored = AnalysisViewConfig::fromArray([
            'primaryDimensionKey' => 'month',
            'metricKeys' => ['count'],
            'configVersion' => 2,
        ]);

        self::assertSame(AnalysisDataSource::Allocations, $restored->dataSource);
        self::assertSame(HospitalPopulationMode::All, $restored->resolvedHospitalPopulationMode());
        self::assertSame(['count'], $restored->resolvedMetricKeys());
    }
}
