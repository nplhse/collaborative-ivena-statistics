<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\ReferenceComparisonModifier;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Exception\InvalidAnalysisConfigurationException;
use App\Statistics\GenericAnalysis\Domain\ReferenceComparison;
use PHPUnit\Framework\TestCase;

final class ReferenceComparisonModifierTest extends TestCase
{
    private ReferenceComparisonModifier $modifier;

    protected function setUp(): void
    {
        $this->modifier = new ReferenceComparisonModifier();
    }

    public function testWithoutReferencesTheQueryStaysAsItIs(): void
    {
        $query = $this->query([], series: 'urgency');

        $this->modifier->validate($query);

        self::assertSame($query, $this->modifier->prepareForExecution($query));
    }

    public function testPutsTheComparisonIntoTheSeriesSlotWithDistinctIds(): void
    {
        $prepared = $this->modifier->prepareForExecution($this->query([7, 3, 7]));

        self::assertSame(ReferenceComparison::DIMENSION_KEY, $prepared->seriesDimensionKey);
        self::assertSame([7, 3], $prepared->referenceHospitalIds);
    }

    public function testRejectsAManualSeriesDimension(): void
    {
        $this->expectException(InvalidAnalysisConfigurationException::class);

        $this->modifier->validate($this->query([7], series: 'urgency'));
    }

    public function testRejectsMoreThanFiveDistinctReferences(): void
    {
        $this->modifier->validate($this->query([1, 2, 3, 4, 5, 5]));

        $this->expectException(InvalidAnalysisConfigurationException::class);
        $this->modifier->validate($this->query([1, 2, 3, 4, 5, 6]));
    }

    public function testIgnoresTheHospitalsDataSource(): void
    {
        $query = $this->query([7], series: 'hospital_tier', dataSource: AnalysisDataSource::Hospitals);

        $this->modifier->validate($query);

        self::assertSame($query, $this->modifier->prepareForExecution($query));
    }

    /**
     * @param list<int> $references
     */
    private function query(array $references, ?string $series = null, AnalysisDataSource $dataSource = AnalysisDataSource::Allocations): AnalysisQuery
    {
        return new AnalysisQuery(
            primaryDimensionKey: 'indication',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: $series,
            dataSource: $dataSource,
            referenceHospitalIds: $references,
        );
    }
}
