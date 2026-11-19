<?php

declare(strict_types=1);

namespace App\Tests\Kpi\Integration\Application\Service;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Kpi\Application\DTO\KpiCardDto;
use App\Kpi\Application\DTO\KpiChangeDto;
use App\Kpi\Application\Service\KpiDashboardService;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Today is 19.11.2026 (Berlin). Current period: 21.10.–19.11.2026, previous period: 21.09.–20.10.2026.
 */
final class KpiDashboardServiceCardsTest extends DatabaseKernelTestCase
{
    use ClockSensitiveTrait;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        self::mockTime('2026-11-19 10:00:00');
    }

    public function testCardsSumTheLast30DaysAndCompareThemWithThe30DaysBefore(): void
    {
        // Current period: 3 imports, 400 rows, 40 rejected → 360 imported, rejection rate 10.00 %.
        $this->insertGlobal('2026-11-19', imports: 2, total: 300, rejected: 30);
        $this->insertGlobal('2026-10-21', imports: 1, total: 100, rejected: 10);
        // Previous period: 300 rows, 40 rejected → 260 imported, rejection rate 13.33 %.
        $this->insertGlobal('2026-10-20', imports: 1, total: 200, rejected: 40);
        $this->insertGlobal('2026-09-21', imports: 1, total: 100, rejected: 0);
        // Before both periods: ignored.
        $this->insertGlobal('2026-09-20', imports: 5, total: 999, rejected: 999);

        $a = $this->hospital(participating: true);
        $b = $this->hospital(participating: true);
        $notParticipating = $this->hospital(participating: false);
        $withoutSuccess = $this->hospital(participating: true);
        $this->insertHospital($a, '2026-11-01', successfulImports: 1);
        $this->insertHospital($a, '2026-10-01', successfulImports: 1);
        $this->insertHospital($b, '2026-11-19', successfulImports: 1);
        $this->insertHospital($notParticipating, '2026-11-10', successfulImports: 1);
        $this->insertHospital($withoutSuccess, '2026-11-10', successfulImports: 0);

        [$hospitals, $records, $rejection] = $this->cards();

        self::assertSame(2, $hospitals->value, 'A and B; not the non-participating one, not the one without a successful import');
        self::assertSame(['participating' => 3], $hospitals->detail?->getParameters());
        self::assertEquals(new KpiChangeDto(1.0, KpiChangeDto::UNIT_COUNT), $hospitals->change, 'previous period: A only');

        self::assertSame(360, $records->value);
        self::assertSame(['total' => 400, 'imports' => 3], $records->detail?->getParameters());
        self::assertEquals(new KpiChangeDto(38.5, KpiChangeDto::UNIT_PERCENT), $records->change, '(360 − 260) / 260 = 38.46 %');

        self::assertSame(10.0, $rejection->value);
        self::assertTrue($rejection->isPercent);
        self::assertSame(['rejected' => 40, 'total' => 400], $rejection->detail?->getParameters());
        self::assertEquals(new KpiChangeDto(-3.33, KpiChangeDto::UNIT_POINTS, lowerIsBetter: true), $rejection->change, '10.00 − 13.33');
        self::assertSame('success', $rejection->change?->tone(), 'a lower rejection rate is an improvement');
    }

    public function testCardsWithoutDataShowNoRateAndNoComparison(): void
    {
        $this->insertGlobal('2026-11-19', imports: 1, total: 50, rejected: 5);

        [$hospitals, $records, $rejection] = $this->cards();

        self::assertSame(0, $hospitals->value);
        self::assertEquals(new KpiChangeDto(0.0, KpiChangeDto::UNIT_COUNT), $hospitals->change);
        self::assertSame(45, $records->value);
        self::assertNull($records->change?->delta, 'no records in the previous period');
        self::assertSame(10.0, $rejection->value);
        self::assertNull($rejection->change?->delta, 'no rate in the previous period');
    }

    public function testRejectionRateIsMissingWithoutRowsInThePeriod(): void
    {
        $this->insertGlobal('2026-10-20', imports: 1, total: 100, rejected: 10);

        [, $records, $rejection] = $this->cards();

        self::assertSame(0, $records->value);
        self::assertEquals(new KpiChangeDto(-100.0, KpiChangeDto::UNIT_PERCENT), $records->change);
        self::assertNull($rejection->value);
        self::assertNull($rejection->change?->delta);
    }

    public function testA90DayPeriodIsComparedWithThe90DaysBefore(): void
    {
        // 90 days: 22.08.–19.11.2026, previous: 24.05.–21.08.2026.
        $this->insertGlobal('2026-08-22', imports: 1, total: 100, rejected: 20);
        $this->insertGlobal('2026-08-21', imports: 1, total: 50, rejected: 0);
        $this->insertGlobal('2026-05-23', imports: 9, total: 999, rejected: 999); // before both periods

        [, $records, $rejection] = self::getContainer()->get(KpiDashboardService::class)->getCards(90)->cards;

        self::assertSame(80, $records->value);
        self::assertEquals(new KpiChangeDto(60.0, KpiChangeDto::UNIT_PERCENT), $records->change, '(80 − 50) / 50');
        self::assertSame(20.0, $rejection->value);
        self::assertEquals(new KpiChangeDto(20.0, KpiChangeDto::UNIT_POINTS, lowerIsBetter: true), $rejection->change, '20 − 0');
    }

    public function testChartHasOneEntryPerDayWithImportedRejectedAndRate(): void
    {
        $this->insertGlobal('2026-11-19', imports: 2, total: 300, rejected: 30);
        $this->insertGlobal('2026-11-17', imports: 1, total: 10, rejected: 0);
        $this->insertGlobal('2026-10-20', imports: 1, total: 100, rejected: 100); // before the 30 days

        $chart = self::getContainer()->get(KpiDashboardService::class)->getChart(30);

        self::assertCount(30, $chart->labels);
        self::assertSame(['21.10.', '19.11.'], [$chart->labels[0], $chart->labels[29]]);
        self::assertSame([10, 0, 270], \array_slice($chart->importedPerDay, 27));
        self::assertSame([0, 0, 30], \array_slice($chart->rejectedPerDay, 27));
        self::assertSame([0.0, null, 10.0], \array_slice($chart->rejectionRatePerDay, 27), 'no rows read on 18.11., no rate');
        self::assertCount(365, self::getContainer()->get(KpiDashboardService::class)->getChart(365)->labels);
    }

    /**
     * @return list<KpiCardDto>
     */
    private function cards(): array
    {
        return self::getContainer()->get(KpiDashboardService::class)->getCards()->cards;
    }

    private function hospital(bool $participating): int
    {
        $state = StateFactory::createOne();
        $hospital = HospitalFactory::createOne([
            'owner' => UserFactory::createOne(['email' => sprintf('kpi-cards-%s@example.test', bin2hex(random_bytes(4)))]),
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::createOne(['state' => $state]),
            'isParticipating' => $participating,
        ]);

        return (int) $hospital->getId();
    }

    private function insertGlobal(string $date, int $imports, int $total, int $rejected): void
    {
        $this->insertRow(null, $date, $imports, $imports, $total, $rejected);
    }

    private function insertHospital(int $hospitalId, string $date, int $successfulImports): void
    {
        $this->insertRow($hospitalId, $date, 1, $successfulImports, 10, 0);
    }

    private function insertRow(?int $hospitalId, string $date, int $imports, int $successfulImports, int $total, int $rejected): void
    {
        $this->connection->insert('kpi_daily', [
            'date' => $date,
            'hospital_id' => $hospitalId,
            'imports_count' => $imports,
            'successful_imports_count' => $successfulImports,
            'records_total' => $total,
            'records_processed' => $total,
            'records_rejected' => $rejected,
            'failed_imports_count' => 0,
            'created_at' => '2026-11-19 10:00:00',
        ]);
    }
}
