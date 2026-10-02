<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\ClosureUnitTableRows;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureBreakdownRow;
use App\Statistics\ClosureAnalytics\UI\Http\Controller\ClosureUnitTableState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ClosureUnitTableRowsTest extends TestCase
{
    public function testSortsByDurationDescendingAndKeepsTheUnitNameSeparate(): void
    {
        $rows = ClosureUnitTableRows::sorted([
            $this->row('2:Short', 'Clinic B', 'Short', 1, 30),
            $this->row('1:Long', 'Clinic A', 'Long', 4, 120),
            $this->row('1:Mid', 'Clinic A', 'Mid', 2, 60),
        ], 210, 'duration', 'desc');

        self::assertSame(['Long', 'Mid', 'Short'], array_map(static fn (\App\Statistics\ClosureAnalytics\Application\DTO\ClosureUnitTableRow $row): string => $row->name, $rows));
        self::assertSame(['Clinic A', 'Clinic A', 'Clinic B'], array_map(static fn (\App\Statistics\ClosureAnalytics\Application\DTO\ClosureUnitTableRow $row): string => $row->hospitalName, $rows));
        self::assertSame(4, $rows[0]->eventCount);
        self::assertEqualsWithDelta(57.14, $rows[0]->sharePercent, 0.01);
    }

    public function testSortsByNameWithinTheSameHospital(): void
    {
        $rows = ClosureUnitTableRows::sorted([
            $this->row('1:Zeta', 'Clinic', 'Zeta', 1, 10),
            $this->row('1:Alpha', 'Clinic', 'Alpha', 3, 10),
        ], 20, 'name', 'asc');

        self::assertSame(['Alpha', 'Zeta'], array_map(static fn (\App\Statistics\ClosureAnalytics\Application\DTO\ClosureUnitTableRow $row): string => $row->name, $rows));
    }

    public function testSortsByHospitalAndEventCountAndBreaksTiesByNameThenHospital(): void
    {
        $byHospital = ClosureUnitTableRows::sorted([
            $this->row('2:A', 'Zeta', 'Same', 1, 10),
            $this->row('1:A', 'Alpha', 'Same', 3, 40),
        ], 50, 'hospital', 'asc');
        self::assertSame(['Alpha', 'Zeta'], array_map(static fn (\App\Statistics\ClosureAnalytics\Application\DTO\ClosureUnitTableRow $row): string => $row->hospitalName, $byHospital));

        $byEvents = ClosureUnitTableRows::sorted([
            $this->row('1:B', 'Clinic', 'Beta', 4, 10),
            $this->row('1:A', 'Clinic', 'Alpha', 1, 10),
        ], 20, 'eventCount', 'asc');
        self::assertSame(['Alpha', 'Beta'], array_map(static fn (\App\Statistics\ClosureAnalytics\Application\DTO\ClosureUnitTableRow $row): string => $row->name, $byEvents));

        $share = ClosureUnitTableRows::sorted([
            $this->row('1:A', 'Clinic', 'Alpha', 1, 10),
        ], 0, 'share', 'desc');
        self::assertSame(0.0, $share[0]->sharePercent);

        $withoutEventCount = ClosureUnitTableRows::sorted([
            new ClosureBreakdownRow('1:A', 'Alpha', 7, 10, 10, 0, 'Clinic'),
        ], 10, 'eventCount', 'asc');
        self::assertSame(7, $withoutEventCount[0]->eventCount);
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('requestStates')]
    public function testReadsPrefixedTableState(array $query, int $page, int $limit, string $sort, string $order): void
    {
        $state = ClosureUnitTableState::fromRequest(Request::create('/statistics/closure-analytics', 'GET', $query));

        self::assertSame($page, $state->page);
        self::assertSame($limit, $state->limit);
        self::assertSame($sort, $state->sortBy);
        self::assertSame($order, $state->orderBy);
    }

    public function testUsesStoredDefaultsWhenTheRequestOmitsThem(): void
    {
        $state = ClosureUnitTableState::fromRequest(
            Request::create('/statistics/closure-analytics'),
            100,
            'name',
            'asc',
        );

        self::assertSame(100, $state->limit);
        self::assertSame('name', $state->sortBy);
        self::assertSame('asc', $state->orderBy);

        $invalid = ClosureUnitTableState::fromRequest(
            Request::create('/statistics/closure-analytics', 'GET', [
                'unitsLimit' => '10',
                'unitsSort' => 'startsAt',
                'unitsOrder' => 'sideways',
            ]),
            50,
            'hospital',
            'asc',
        );

        self::assertSame(50, $invalid->limit);
        self::assertSame('hospital', $invalid->sortBy);
        self::assertSame('asc', $invalid->orderBy);

        $ignored = ClosureUnitTableState::fromRequest(
            Request::create('/statistics/closure-analytics', 'GET', [
                'unitsPage' => ['2'],
                'unitsLimit' => ['50'],
            ]),
            7,
            'not-a-column',
            'sideways',
        );
        self::assertSame(1, $ignored->page);
        self::assertSame(25, $ignored->limit);
        self::assertSame('duration', $ignored->sortBy);
        self::assertSame('desc', $ignored->orderBy);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, int, string, string}>
     */
    public static function requestStates(): iterable
    {
        yield 'defaults' => [[], 1, 25, 'duration', 'desc'];
        yield 'explicit' => [[
            'unitsPage' => '2',
            'unitsLimit' => '50',
            'unitsSort' => 'name',
            'unitsOrder' => 'asc',
            'sortBy' => 'startsAt',
            'page' => '9',
        ], 2, 50, 'name', 'asc'];
        yield 'invalid values fall back' => [[
            'unitsPage' => '0',
            'unitsLimit' => '10',
            'unitsSort' => 'startsAt',
            'unitsOrder' => 'sideways',
        ], 1, 25, 'duration', 'desc'];
    }

    private function row(string $key, string $hospital, string $name, int $events, int $minutes): ClosureBreakdownRow
    {
        return new ClosureBreakdownRow($key, $name, $events, $minutes, $minutes, 0, $hospital, $events);
    }
}
