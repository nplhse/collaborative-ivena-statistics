<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalSize;
use App\DataFixtures\Allocation\ImportBatch;
use App\DataFixtures\Allocation\ImportBatchPlanner;
use App\DataFixtures\FixtureVolume;
use PHPUnit\Framework\TestCase;

final class ImportBatchPlannerTest extends TestCase
{
    public function testOneImportPerHospitalAndMonthOverWholeMonths(): void
    {
        $batches = $this->plan(scale: 1.0);

        // 1 Oct 2025 … 31 Oct 2026 (13 months) for two participating hospitals; the third does not take part.
        self::assertCount(26, $batches);
        self::assertSame('IVENA allocations 2025-10', $batches[0]->importName);
        self::assertSame('2025-10-01', array_key_first($batches[0]->dailyCounts));
        self::assertCount(31, $batches[0]->dailyCounts);
        self::assertSame('IVENA allocations 2026-10', $batches[12]->importName);
    }

    public function testDailyCountsFollowTheHospitalSizeAndAreNotRound(): void
    {
        $batches = $this->plan(scale: 1.0);
        $large = $this->total($batches, 'Großklinikum');
        $small = $this->total($batches, 'Landkrankenhaus');
        $days = 396;

        self::assertEqualsWithDelta(40 * $days, $large, 40 * $days * 0.05, 'about 40 per day for a large hospital');
        self::assertEqualsWithDelta(10 * $days, $small, 10 * $days * 0.08, 'about 10 per day for a small hospital');
        self::assertNotSame(0, ($large + $small) % 100, 'totals are not round numbers');

        $weekdays = [];
        foreach ($batches as $batch) {
            if ('Großklinikum' !== $batch->hospital->getName()) {
                continue;
            }
            foreach ($batch->dailyCounts as $day => $count) {
                $weekdays[(int) new \DateTimeImmutable($day)->format('N')][] = $count;
            }
        }
        self::assertLessThan(array_sum($weekdays[1]) / \count($weekdays[1]), array_sum($weekdays[7]) / \count($weekdays[7]), 'fewer allocations on Sundays than on Mondays');
    }

    public function testTheScaleMultipliesTheVolume(): void
    {
        $full = $this->total($this->plan(scale: 1.0), 'Großklinikum');
        $tenth = $this->total($this->plan(scale: 0.1), 'Großklinikum');

        self::assertEqualsWithDelta($full / 10, $tenth, $full / 10 * 0.1);
    }

    /**
     * @return list<ImportBatch>
     */
    private function plan(float $scale): array
    {
        mt_srand(740);
        $volume = new FixtureVolume(
            hospitalsActive: 2,
            months: 12,
            allocationsPerDay: ['Small' => 10.0, 'Medium' => 15.0, 'Large' => 40.0],
            scale: $scale,
            mciCases: 0,
            seed: 740,
            rebuildProjection: false,
        );

        return new ImportBatchPlanner()->plan(
            $volume,
            [
                $this->hospital('Landkrankenhaus', HospitalSize::SMALL, true),
                $this->hospital('Großklinikum', HospitalSize::LARGE, true),
                $this->hospital('Nicht dabei', HospitalSize::MEDIUM, false),
            ],
            new \DateTimeImmutable('2025-10-01'),
            new \DateTimeImmutable('2026-10-31 23:59:59'),
        );
    }

    /**
     * @param list<ImportBatch> $batches
     */
    private function total(array $batches, string $hospital): int
    {
        $total = 0;
        foreach ($batches as $batch) {
            if ($hospital === $batch->hospital->getName()) {
                $total += $batch->allocationCount();
            }
        }

        return $total;
    }

    private function hospital(string $name, HospitalSize $size, bool $participating): Hospital
    {
        return new Hospital()->setName($name)->setSize($size)->setIsParticipating($participating);
    }
}
