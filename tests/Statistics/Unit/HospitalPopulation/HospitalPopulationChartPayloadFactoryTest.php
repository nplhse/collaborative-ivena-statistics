<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\HospitalPopulation;

use App\Statistics\HospitalPopulation\Application\DescriptiveStatisticsCalculator;
use App\Statistics\HospitalPopulation\Application\DTO\AllocationBasisSummary;
use App\Statistics\HospitalPopulation\Application\DTO\AllocationCrossTable;
use App\Statistics\HospitalPopulation\Application\DTO\BedsCategoryBoxPlotRow;
use App\Statistics\HospitalPopulation\Application\DTO\CoverageCrossTable;
use App\Statistics\HospitalPopulation\Application\DTO\DescriptiveStats;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationDashboardResult;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationOverview;
use App\Statistics\HospitalPopulation\UI\Http\Controller\HospitalPopulationChartPayloadFactory;
use PHPUnit\Framework\TestCase;

final class HospitalPopulationChartPayloadFactoryTest extends TestCase
{
    private HospitalPopulationChartPayloadFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HospitalPopulationChartPayloadFactory();
    }

    public function testBuildsSeparateCareLevelAndLocationBoxPlotPayloads(): void
    {
        $calculator = new DescriptiveStatisticsCalculator();
        $careLevelRows = [
            new BedsCategoryBoxPlotRow('Basic', 'Basic', $calculator->calculate([100, 200]), $calculator->calculate([100])),
            new BedsCategoryBoxPlotRow('Full', 'Full', $calculator->calculate([500]), $calculator->calculate([500])),
        ];
        $locationRows = [
            new BedsCategoryBoxPlotRow('Urban', 'Urban', $calculator->calculate([100]), $calculator->calculate([100])),
            new BedsCategoryBoxPlotRow('Rural', 'Rural', $calculator->calculate([500]), $calculator->calculate([500])),
        ];

        $payload = $this->factory->create($this->dashboardResult($careLevelRows, $locationRows));

        self::assertArrayHasKey('bedsBoxPlotByCareLevel', $payload);
        self::assertArrayHasKey('bedsBoxPlotByLocation', $payload);
        self::assertArrayHasKey('population', $payload['bedsBoxPlotByCareLevel']);
        self::assertArrayHasKey('participants', $payload['bedsBoxPlotByCareLevel']);
        self::assertCount(1, $payload['bedsBoxPlotByCareLevel']['population']['series']);
        self::assertCount(1, $payload['bedsBoxPlotByCareLevel']['participants']['series']);
        self::assertSame('All hospitals', $payload['bedsBoxPlotByCareLevel']['population']['series'][0]['name']);
        self::assertSame('Participants', $payload['bedsBoxPlotByCareLevel']['participants']['series'][0]['name']);
        self::assertSame('Basic', $payload['bedsBoxPlotByCareLevel']['population']['series'][0]['data'][0]['x']);
        self::assertSame(100.0, $payload['bedsBoxPlotByCareLevel']['population']['series'][0]['data'][0]['y'][0]);
        self::assertSame(200.0, $payload['bedsBoxPlotByCareLevel']['population']['series'][0]['data'][0]['y'][4]);
        self::assertSame('Basic', $payload['bedsBoxPlotByCareLevel']['participants']['series'][0]['data'][0]['x']);
        self::assertSame('Urban', $payload['bedsBoxPlotByLocation']['population']['series'][0]['data'][0]['x']);
        self::assertSame('Rural', $payload['bedsBoxPlotByLocation']['population']['series'][0]['data'][1]['x']);
    }

    public function testBuildsAllocationCategoryBarPayloads(): void
    {
        $payload = $this->factory->create($this->dashboardResult([], []));

        self::assertArrayHasKey('allocationByTier', $payload);
        self::assertArrayHasKey('allocationBySize', $payload);
        self::assertArrayHasKey('allocationByLocation', $payload);
        self::assertSame([], $payload['allocationByTier']['categories']);
    }

    /**
     * @param list<BedsCategoryBoxPlotRow> $careLevelRows
     * @param list<BedsCategoryBoxPlotRow> $locationRows
     */
    private function dashboardResult(array $careLevelRows, array $locationRows): HospitalPopulationDashboardResult
    {
        $emptyStats = new DescriptiveStats(0, null, null, null, null, null, null, null, null, null, null);

        return new HospitalPopulationDashboardResult(
            overview: new HospitalPopulationOverview(0, 0, 0.0, new CoverageCrossTable([], []), new CoverageCrossTable([], [])),
            regionalCoverage: [],
            bedsPopulation: $emptyStats,
            bedsParticipants: $emptyStats,
            allocationBasis: new AllocationBasisSummary(
                bySize: [],
                byTier: [],
                byLocation: [],
                sizeByTierCrossTable: new AllocationCrossTable([], []),
                locationByTierCrossTable: new AllocationCrossTable([], []),
            ),
            mapMarkers: [],
            mapChoropleth: [],
            bedsBoxPlotByCareLevel: $careLevelRows,
            bedsBoxPlotByLocation: $locationRows,
        );
    }
}
