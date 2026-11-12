<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Overview;

use App\Encounter\Application\Contract\EncounterOverviewReaderInterface;
use App\Encounter\Application\Overview\EncounterCoverage;
use App\Encounter\Application\Overview\EncounterOverviewFactory;
use App\Encounter\Application\Overview\EncounterPeriod;
use App\Encounter\Application\Overview\OverviewCount;
use App\Encounter\Application\Overview\ProcessInterval;
use App\Encounter\Application\Overview\ProcessTimeBox;
use App\Encounter\Application\Overview\ProcessTimeDistribution;
use App\Encounter\Application\Overview\ProcessTimeStatistics;
use App\Encounter\Application\Overview\TimeGrain;
use PHPUnit\Framework\TestCase;

/**
 * Suppression and ordering rules of the overview (#1055) on stubbed counts.
 */
final class EncounterOverviewFactoryTest extends TestCase
{
    public function testCountsBelowFiveAreSuppressedFromOneToFour(): void
    {
        self::assertSame([null, null, 5, 0], [
            new OverviewCount('a', 1, 10)->shownValue(),
            new OverviewCount('b', 4, 10)->shownValue(),
            new OverviewCount('c', 5, 10)->shownValue(),
            new OverviewCount('d', 0, 10)->shownValue(),
        ]);
        self::assertSame(50.0, new OverviewCount('c', 5, 10)->share);
        self::assertNull(new OverviewCount('e', 0, 0)->share, 'No share without a denominator.');
        self::assertNull(new OverviewCount('d', 0, 10)->share, 'A dash needs no share.');
    }

    /**
     * Twelve diagrams with 12, 11, … 1 visits plus `a_tie` with 12: the top ten are listed, d03 + d02 + d01 = 6 visits are
     * "other diagrams", 7 visits have no diagram. Ties are ordered by code.
     */
    public function testTopTenDiagramsThenOtherThenNotDocumented(): void
    {
        $counts = ['' => 7];
        foreach (range(1, 12) as $visits) {
            $counts[sprintf('d%02d', $visits)] = $visits;
        }
        $counts['a_tie'] = 12;

        $overview = $this->factory(diagrams: $counts, visits: 7 + 78 + 12)->create(1, EncounterPeriod::allTime());

        self::assertSame(['a_tie', 'd12', 'd11', 'd10', 'd09', 'd08', 'd07', 'd06', 'd05', 'd04', 'other', ''], array_map(static fn (OverviewCount $row): string => $row->key, $overview->diagrams));
        self::assertSame(10, $overview->diagramsShown);
        self::assertSame(3 + 2 + 1, $overview->diagrams[10]->shownValue(), 'd03, d02 and d01 are summed up.');
        self::assertSame(7, $overview->diagrams[11]->shownValue());
        self::assertEqualsWithDelta(12 * 100 / 97, $overview->diagrams[0]->share, 0.0001, 'Shares of all visits of the period.');
    }

    public function testCategoriesListEveryCodeInMtsOrderAndCareLevelsUseTheirOwnDenominator(): void
    {
        $overview = $this->factory(categories: ['red' => 6, '' => 4], careLevels: ['icu' => 5, '' => 5], visits: 10)->create(1, EncounterPeriod::allTime());

        self::assertSame(['red', 'orange', 'yellow', 'green', 'blue', ''], array_map(static fn (OverviewCount $row): string => $row->key, $overview->categories));
        self::assertSame(60.0, $overview->categories[0]->share);
        self::assertSame(10, $overview->careLevelDenominator);
        self::assertSame(50.0, $overview->careLevels[2]->share, 'icu: 5 of 10 admissions and transfers.');
        self::assertSame(['ward', 'monitoring', 'icu', 'or', 'unknown', ''], array_map(static fn (OverviewCount $row): string => $row->key, $overview->careLevels));
    }

    public function testMedianNeedsFiveValidDurations(): void
    {
        $overview = $this->factory(validDurations: 4)->create(1, EncounterPeriod::allTime());
        self::assertTrue($overview->processTimes[0]->suppressed);
        self::assertNull($overview->processTimes[0]->median);
        self::assertNull($overview->processTimes[0]->q1);

        $overview = $this->factory(validDurations: 5)->create(1, EncounterPeriod::allTime());
        self::assertFalse($overview->processTimes[0]->suppressed);
        self::assertSame(30.0, $overview->processTimes[0]->median);
    }

    public function testBoxNeedsFiveDurationsAndShowsP10ToP90(): void
    {
        self::assertNull(new ProcessTimeBox('red', new ProcessTimeDistribution('red', 4, 1.0, 2.0, 3.0, 4.0, 5.0))->values);
        self::assertSame([1.0, 2.0, 3.0, 4.0, 5.0], new ProcessTimeBox('red', new ProcessTimeDistribution('red', 5, 1.0, 2.0, 3.0, 4.0, 5.0))->values);
    }

    public function testBucketKeysFollowTheStatisticsTimeSeries(): void
    {
        $day = new \DateTimeImmutable('2026-10-11');
        self::assertSame('2026-10-11', TimeGrain::Day->key($day));
        self::assertSame('2026-10', TimeGrain::Month->key($day));
    }

    /**
     * @param array<string, int> $diagrams
     * @param array<string, int> $categories
     * @param array<string, int> $careLevels
     */
    private function factory(array $diagrams = [], array $categories = [], array $careLevels = [], int $visits = 0, int $validDurations = 0): EncounterOverviewFactory
    {
        $reader = $this->createStub(EncounterOverviewReaderInterface::class);
        $reader->method('coverage')->willReturn(new EncounterCoverage($visits, 1, 1, null, null));
        $reader->method('visitsByRouteAndLink')->willReturn([]);
        $reader->method('visitsByCategory')->willReturn($categories);
        $reader->method('visitsByDiagram')->willReturn($diagrams);
        $reader->method('visitsByDisposition')->willReturn([]);
        $reader->method('visitsByCareLevel')->willReturn($careLevels);
        $reader->method('processTimeByCategory')->willReturn([]);
        $reader->method('visitsByBucket')->willReturn([]);
        $reader->method('processTime')->willReturnCallback(static fn (int $hospital, EncounterPeriod $period, ProcessInterval $interval): ProcessTimeStatistics => new ProcessTimeStatistics($interval, $validDurations, 0, 0, 0, 20.0, 30.0, 40.0));

        return new EncounterOverviewFactory($reader);
    }
}
