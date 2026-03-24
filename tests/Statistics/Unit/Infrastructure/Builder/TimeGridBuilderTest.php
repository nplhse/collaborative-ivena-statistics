<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Infrastructure\Builder;

use App\Statistics\Domain\Enum\TimeGridMode;
use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Builder\TimeGridBuilder;
use App\Statistics\Infrastructure\Reader\TimeGridSeriesReaderInterface;
use App\Statistics\Infrastructure\Util\Period;
use PHPUnit\Framework\TestCase;

final class TimeGridBuilderTest extends TestCase
{
    /**
     * @param array<string, object|null> $primaryByPeriod
     * @param array<string, object|null> $baseByPeriod
     */
    private function makeBuilder(array $primaryByPeriod, array $baseByPeriod = []): TimeGridBuilder
    {
        $reader = new readonly class($primaryByPeriod, $baseByPeriod) implements TimeGridSeriesReaderInterface {
            /**
             * @param array<string, object|null> $primaryByPeriod
             * @param array<string, object|null> $baseByPeriod
             */
            public function __construct(
                private array $primaryByPeriod,
                private array $baseByPeriod,
            ) {
            }

            public function loadSeries(Scope $scope, array $columns): array
            {
                $map = 'base' === $scope->scopeId ? $this->baseByPeriod : $this->primaryByPeriod;
                $out = [];
                foreach ($columns as $col) {
                    if (($col['isTotal'] ?? false) === true) {
                        continue;
                    }
                    $pk = $col['periodKey'];
                    $out[$pk] = $map[$pk] ?? null;
                }

                return $out;
            }
        };

        return new TimeGridBuilder($reader);
    }

    public function testRawModePassesThroughValuesWithoutDeltas(): void
    {
        $primary = new Scope('public', 'all', Period::YEAR, '2025-01-01');
        $builder = $this->makeBuilder([
            '2025-01-01' => (object) ['total' => 10],
            '2025-02-01' => (object) ['total' => 20],
        ]);

        $result = $builder->build(
            $primary,
            [['label' => 'Total', 'key' => 'total', 'format' => 'int']],
            TimeGridMode::RAW
        );

        self::assertArrayHasKey('rows', $result);
        $cells = $result['rows'][0]['cells'];
        self::assertSame(10.0, $cells[0]->value);
        self::assertNull($cells[0]->deltaAbs);
        self::assertNull($cells[0]->deltaPct);
        self::assertNull($cells[0]->compare);
        self::assertSame(20.0, $cells[1]->value);
        $totalCell = $cells[array_key_last($cells)];
        self::assertSame(30, $totalCell->value);
    }

    public function testDeltaModeComputesAbsAndPctBetweenPeriods(): void
    {
        $primary = new Scope('public', 'all', Period::YEAR, '2025-01-01');
        $builder = $this->makeBuilder([
            '2025-01-01' => (object) ['total' => 10],
            '2025-02-01' => (object) ['total' => 15],
        ]);

        $result = $builder->build(
            $primary,
            [['label' => 'Total', 'key' => 'total', 'format' => 'int']],
            TimeGridMode::DELTA
        );

        $cells = $result['rows'][0]['cells'];
        self::assertNull($cells[0]->deltaAbs);
        self::assertSame(5.0, $cells[1]->deltaAbs);
        self::assertSame(50.0, $cells[1]->deltaPct);
    }

    public function testDeltaModeSkipsPctWhenPreviousNumericIsZero(): void
    {
        $primary = new Scope('public', 'all', Period::YEAR, '2025-01-01');
        $builder = $this->makeBuilder([
            '2025-01-01' => (object) ['total' => 0],
            '2025-02-01' => (object) ['total' => 5],
        ]);

        $result = $builder->build(
            $primary,
            [['label' => 'Total', 'key' => 'total', 'format' => 'int']],
            TimeGridMode::DELTA
        );

        $cells = $result['rows'][0]['cells'];
        self::assertSame(5.0, $cells[1]->deltaAbs);
        self::assertNull($cells[1]->deltaPct);
    }

    public function testCompareModeUsesBaselineSeries(): void
    {
        $primaryScope = new Scope('public', 'primary', Period::YEAR, '2025-01-01');
        $baseScope = new Scope('public', 'base', Period::YEAR, '2025-01-01');

        $builder = $this->makeBuilder(
            [
                '2025-01-01' => (object) ['total' => 100],
                '2025-02-01' => (object) ['total' => 50],
            ],
            [
                '2025-01-01' => (object) ['total' => 80],
                '2025-02-01' => (object) ['total' => 40],
            ],
        );

        $result = $builder->build(
            $primaryScope,
            [['label' => 'Total', 'key' => 'total', 'format' => 'int']],
            TimeGridMode::COMPARE,
            $baseScope
        );

        $cells = $result['rows'][0]['cells'];
        self::assertSame(100.0, $cells[0]->value);
        self::assertSame(80.0, $cells[0]->compare);
        self::assertSame(20.0, $cells[0]->deltaAbs);
        self::assertSame(25.0, $cells[0]->deltaPct);
    }

    public function testCompareTotalCellAggregatesBaselineAndPrimary(): void
    {
        $primaryScope = new Scope('public', 'primary', Period::QUARTER, '2025-03-01');
        $baseScope = new Scope('public', 'base', Period::QUARTER, '2025-03-01');

        $builder = $this->makeBuilder(
            [
                '2025-01-01' => (object) ['total' => 10],
                '2025-02-01' => (object) ['total' => 20],
                '2025-03-01' => (object) ['total' => 30],
            ],
            [
                '2025-01-01' => (object) ['total' => 5],
                '2025-02-01' => (object) ['total' => 5],
                '2025-03-01' => (object) ['total' => 10],
            ],
        );

        $result = $builder->build(
            $primaryScope,
            [['label' => 'Total', 'key' => 'total', 'format' => 'int']],
            TimeGridMode::COMPARE,
            $baseScope
        );

        $cells = $result['rows'][0]['cells'];
        $totalCell = $cells[array_key_last($cells)];
        self::assertSame(60, $totalCell->value);
        self::assertSame(20, $totalCell->compare);
        self::assertSame(40.0, $totalCell->deltaAbs);
        self::assertSame(200.0, $totalCell->deltaPct);
    }

    public function testPctFormatRowUsesMeanForTimeAndTotalCells(): void
    {
        $primary = new Scope('public', 'all', Period::YEAR, '2025-01-01');
        $builder = $this->makeBuilder([
            '2025-01-01' => (object) ['pctMale' => 10.0],
            '2025-02-01' => (object) ['pctMale' => 30.0],
        ]);

        $result = $builder->build(
            $primary,
            [['label' => '% Male', 'key' => 'pctMale', 'format' => 'pct']],
            TimeGridMode::RAW
        );

        $cells = $result['rows'][0]['cells'];
        self::assertSame(10.0, $cells[0]->value);
        self::assertSame(30.0, $cells[1]->value);
        $totalCell = $cells[array_key_last($cells)];
        self::assertSame(20.0, $totalCell->value);
    }

    public function testCompareModeWithZeroBaselineOmitsPctOnTotal(): void
    {
        $primaryScope = new Scope('public', 'primary', Period::YEAR, '2025-01-01');
        $baseScope = new Scope('public', 'base', Period::YEAR, '2025-01-01');

        $builder = $this->makeBuilder(
            [
                '2025-01-01' => (object) ['total' => 5],
                '2025-02-01' => (object) ['total' => 5],
            ],
            [
                '2025-01-01' => (object) ['total' => 0],
                '2025-02-01' => (object) ['total' => 0],
            ],
        );

        $result = $builder->build(
            $primaryScope,
            [['label' => 'Total', 'key' => 'total', 'format' => 'int']],
            TimeGridMode::COMPARE,
            $baseScope
        );

        $cells = $result['rows'][0]['cells'];
        $totalCell = $cells[array_key_last($cells)];
        self::assertSame(10, $totalCell->value);
        self::assertSame(0, $totalCell->compare);
        self::assertSame(10.0, $totalCell->deltaAbs);
        self::assertNull($totalCell->deltaPct);
    }
}
