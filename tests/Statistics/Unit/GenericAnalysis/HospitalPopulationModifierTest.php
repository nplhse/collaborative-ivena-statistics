<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\HospitalPopulationModifier;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalPopulationMode;
use App\Statistics\GenericAnalysis\Domain\Exception\InvalidAnalysisConfigurationException;
use PHPUnit\Framework\TestCase;

final class HospitalPopulationModifierTest extends TestCase
{
    private HospitalPopulationModifier $modifier;

    protected function setUp(): void
    {
        $this->modifier = new HospitalPopulationModifier();
    }

    public function testSupportsHospitalsOnly(): void
    {
        self::assertTrue($this->modifier->supports(AnalysisDataSource::Hospitals));
        self::assertFalse($this->modifier->supports(AnalysisDataSource::Allocations));
    }

    public function testCompareRejectsManualSeriesDimension(): void
    {
        $this->expectException(InvalidAnalysisConfigurationException::class);

        $this->modifier->validate(GenericAnalysisTestFixtures::defaultQuery(
            series: 'hospital_tier',
            dataSource: AnalysisDataSource::Hospitals,
            hospitalPopulationMode: HospitalPopulationMode::Compare,
        ));
    }

    public function testCompareInjectsPopulationGroupSeries(): void
    {
        $prepared = $this->modifier->prepareForExecution(GenericAnalysisTestFixtures::defaultQuery(
            dataSource: AnalysisDataSource::Hospitals,
            hospitalPopulationMode: HospitalPopulationMode::Compare,
        ));

        self::assertSame('hospital_population_group', $prepared->seriesDimensionKey);
    }

    public function testCompareWithPopulationGroupAsPrimaryKeepsManualSeries(): void
    {
        $query = GenericAnalysisTestFixtures::defaultQuery(
            primary: 'hospital_population_group',
            series: 'hospital_tier',
            dataSource: AnalysisDataSource::Hospitals,
            hospitalPopulationMode: HospitalPopulationMode::Compare,
        );

        $this->modifier->validate($query);
        $prepared = $this->modifier->prepareForExecution($query);

        self::assertSame('hospital_tier', $prepared->seriesDimensionKey);
    }
}
