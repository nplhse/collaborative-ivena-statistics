<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Domain;

use App\Encounter\Domain\Entity\PlausibilityCell;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use App\Encounter\Domain\Enum\PlausibilityCellSource;
use App\Encounter\Domain\Enum\PlausibilitySnapshotOrigin;
use App\Encounter\Domain\Enum\PlausibilitySnapshotStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cells hold 0 … +3 points and either a PZC or a problem area (ADR 033); snapshots go draft → active → retired.
 */
final class PlausibilityCellTest extends TestCase
{
    public function testPointsFromZeroToThree(): void
    {
        foreach ([0, 1, 2, 3] as $points) {
            self::assertSame($points, $this->cell(711, null, $points)->getPoints());
        }
    }

    /**
     * @return iterable<string, array{?int, ?string, int, ?string}>
     */
    public static function invalidCells(): iterable
    {
        yield 'negative points' => [711, null, -1, null];
        yield 'four points' => [711, null, 4, null];
        yield 'neither PZC nor area' => [null, null, 3, null];
        yield 'PZC and area' => [711, 'urologie', 3, null];
        yield 'indicator without PZC' => [null, 'urologie', 3, 'harnverhalt'];
    }

    #[DataProvider('invalidCells')]
    public function testRefusesInvalidCells(?int $pzc, ?string $area, int $points, ?string $indicator): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cell($pzc, $area, $points, $indicator);
    }

    public function testSnapshotLifeCycle(): void
    {
        $snapshot = new PlausibilitySnapshot(PlausibilitySnapshotOrigin::Seed, new \DateTimeImmutable('2026-11-13 10:00'));
        self::assertSame(PlausibilitySnapshotStatus::Draft, $snapshot->getStatus());

        $snapshot->activate(new \DateTimeImmutable('2026-11-13 11:00'));
        self::assertSame(PlausibilitySnapshotStatus::Active, $snapshot->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-11-13 11:00'), $snapshot->getActivatedAt());

        $snapshot->retire(new \DateTimeImmutable('2026-11-14 09:00'));
        self::assertSame(PlausibilitySnapshotStatus::Retired, $snapshot->getStatus());

        $this->expectException(\LogicException::class);
        $snapshot->activate(new \DateTimeImmutable('2026-11-14 10:00'));
    }

    private function cell(?int $pzc, ?string $area, int $points, ?string $indicator = null): PlausibilityCell
    {
        $snapshot = new PlausibilitySnapshot(PlausibilitySnapshotOrigin::Learned, new \DateTimeImmutable('2026-11-13'));

        return new PlausibilityCell($snapshot, $pzc, $area, 'urologisches_problem', $points, PlausibilityCellSource::Curated, indicatorCode: $indicator);
    }
}
