<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalSize;
use App\DataFixtures\FixtureVolume;
use App\DataFixtures\Generator\Application\ImportBatch;
use App\DataFixtures\Generator\Application\ImportBatchPlanner;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ImportBatchPlannerTest extends TestCase
{
    public function testOneImportPerHospitalAndMonthOverWholeMonths(): void
    {
        $batches = $this->plan(scale: 1.0);

        // 1 Oct 2025 … 31 Oct 2026 (13 months) for two participating hospitals; the third does not take part.
        self::assertCount(26, $batches);
        self::assertSame('IVENA allocations 2025-10', $batches[0]->importName);
        self::assertSame('2025-10', $batches[0]->month);
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

    public function testTheSameSeedGivesTheSameDailyCounts(): void
    {
        $counts = static fn (array $batches): array => array_map(static fn (ImportBatch $batch): array => $batch->dailyCounts, $batches);

        self::assertSame($counts($this->plan(scale: 1.0)), $counts($this->plan(scale: 1.0)));
        self::assertNotSame($counts($this->plan(scale: 1.0)), $counts($this->plan(scale: 1.0, seed: 741)));
    }

    public function testAHospitalGetsTheSameCountsWithOrWithoutOtherHospitals(): void
    {
        $large = $this->hospital('Großklinikum', HospitalSize::LARGE, true);
        $alone = $this->counts($this->plan(scale: 1.0, hospitals: [$large]), 'Großklinikum');
        $together = $this->counts($this->plan(scale: 1.0), 'Großklinikum');

        self::assertSame($alone, $together);
    }

    public function testAMonthGetsTheSameCountsAloneAndInsideALongerPeriod(): void
    {
        // With a trend, too: it is relative to a fixed day, not to the end of the period.
        $september = $this->plan(scale: 1.0, from: '2026-09-01', until: '2026-09-30 23:59:59', trend: 0.02);
        $year = $this->plan(scale: 1.0, trend: 0.02);

        $inYear = array_values(array_filter($year, static fn (ImportBatch $batch): bool => '2026-09' === $batch->month && 'Großklinikum' === $batch->hospital->getName()));
        $alone = array_values(array_filter($september, static fn (ImportBatch $batch): bool => 'Großklinikum' === $batch->hospital->getName()));

        self::assertCount(1, $alone);
        self::assertSame($inYear[0]->dailyCounts, $alone[0]->dailyCounts);
    }

    public function testTheTrendGrowsTheVolumeFromYearToYear(): void
    {
        $volume = $this->volume(trend: 0.02);

        self::assertSame(1.0, $volume->trendFactor(new \DateTimeImmutable(FixtureVolume::TREND_REFERENCE_DAY)));
        self::assertEqualsWithDelta(1.02, $volume->trendFactor(new \DateTimeImmutable('2027-01-01')), 0.0005);
        self::assertEqualsWithDelta(1 / 1.02, $volume->trendFactor(new \DateTimeImmutable('2025-01-01')), 0.0005);
        self::assertSame(1.0, $this->volume(trend: 0.0)->trendFactor(new \DateTimeImmutable('2030-06-01')), 'no trend');

        $earlier = $this->total($this->plan(scale: 1.0, from: '2024-10-01', until: '2025-09-30 23:59:59', trend: 0.10), 'Großklinikum');
        $later = $this->total($this->plan(scale: 1.0, from: '2026-10-01', until: '2027-09-30 23:59:59', trend: 0.10), 'Großklinikum');
        self::assertGreaterThan($earlier * 1.15, $later, 'two years at 10 % give about 21 % more');
    }

    public function testTheScaleMultipliesTheVolume(): void
    {
        $full = $this->total($this->plan(scale: 1.0), 'Großklinikum');
        $tenth = $this->total($this->plan(scale: 0.1), 'Großklinikum');

        self::assertEqualsWithDelta($full / 10, $tenth, $full / 10 * 0.1);
    }

    private function volume(float $trend, float $scale = 1.0, int $seed = 740): FixtureVolume
    {
        return new FixtureVolume(
            profile: 'test',
            hospitals: ['Large' => 1, 'Small' => 1],
            months: 12,
            allocationsPerDay: ['Small' => 10.0, 'Medium' => 15.0, 'Large' => 40.0],
            scale: $scale,
            mciCasesPerHospitalAndYear: 0.0,
            seed: $seed,
            rebuildProjection: false,
            trendPerYear: $trend,
        );
    }

    /**
     * @param list<Hospital>|null $hospitals
     *
     * @return list<ImportBatch>
     */
    private function plan(float $scale, int $seed = 740, ?array $hospitals = null, string $from = '2025-10-01', string $until = '2026-10-31 23:59:59', float $trend = 0.0): array
    {
        return new ImportBatchPlanner(new RandomStreams())->plan(
            $this->volume($trend, $scale, $seed),
            $hospitals ?? [
                $this->hospital('Landkrankenhaus', HospitalSize::SMALL, true),
                $this->hospital('Großklinikum', HospitalSize::LARGE, true),
                $this->hospital('Nicht dabei', HospitalSize::MEDIUM, false),
            ],
            new \DateTimeImmutable($from),
            new \DateTimeImmutable($until),
        );
    }

    /**
     * @param list<ImportBatch> $batches
     *
     * @return array<string, int>
     */
    private function counts(array $batches, string $hospital): array
    {
        $counts = [];
        foreach ($batches as $batch) {
            if ($hospital === $batch->hospital->getName()) {
                $counts += $batch->dailyCounts;
            }
        }

        return $counts;
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
        // Synthetic hospitals have a name-based public id; the random streams are keyed by it.
        return new Hospital()
            ->setName($name)
            ->setSize($size)
            ->setIsParticipating($participating)
            ->setPublicId(Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_OID), $name));
    }
}
