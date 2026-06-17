<?php

declare(strict_types=1);

namespace App\Tests\Kpi\Integration\Repository;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Kpi\Infrastructure\Repository\KpiDailyRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;

final class KpiDailyRepositoryMonthlyReminderQueryTest extends DatabaseKernelTestCase
{
    private KpiDailyRepository $repository;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->repository = $container->get(KpiDailyRepository::class);
        $this->connection = $container->get(Connection::class);
    }

    public function testRejectionRateForHospitalInRangeAggregatesDailyRows(): void
    {
        $hospitalId = $this->seedHospitalId();
        $this->insertHospitalKpi($hospitalId, '2026-04-01', 100, 10);
        $this->insertHospitalKpi($hospitalId, '2026-05-01', 200, 20);

        $aprilRate = $this->repository->rejectionRateForHospitalInRange(
            $hospitalId,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-05-01'),
        );
        $mayRate = $this->repository->rejectionRateForHospitalInRange(
            $hospitalId,
            new \DateTimeImmutable('2026-05-01'),
            new \DateTimeImmutable('2026-06-01'),
        );

        self::assertSame(10.0, $aprilRate);
        self::assertSame(10.0, $mayRate);
    }

    public function testRejectionRateForHospitalInRangeReturnsNullWithoutData(): void
    {
        $hospitalId = $this->seedHospitalId();

        self::assertNull($this->repository->rejectionRateForHospitalInRange(
            $hospitalId,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-05-01'),
        ));
    }

    public function testCountActiveHospitalsLast30DaysCountsDistinctHospitalsWithSuccessfulImports(): void
    {
        $firstHospitalId = $this->seedHospitalId();
        $secondHospitalId = $this->seedHospitalId();
        $inactiveHospitalId = $this->seedHospitalId();

        $today = new \DateTimeImmutable('today')->format('Y-m-d');
        $this->insertHospitalKpi($firstHospitalId, $today, 50, 5, successfulImports: 1);
        $this->insertHospitalKpi($secondHospitalId, $today, 40, 4, successfulImports: 1);
        $this->insertHospitalKpi($inactiveHospitalId, $today, 10, 1, successfulImports: 0);

        self::assertSame(2, $this->repository->countActiveHospitalsLast30Days());
    }

    private function seedHospitalId(): int
    {
        $user = UserFactory::createOne([
            'email' => sprintf('kpi-repo-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        return (int) $hospital->getId();
    }

    private function insertHospitalKpi(
        int $hospitalId,
        string $date,
        int $recordsTotal,
        int $recordsRejected,
        int $successfulImports = 1,
    ): void {
        $this->connection->insert('kpi_daily', [
            'date' => $date,
            'hospital_id' => $hospitalId,
            'imports_count' => 1,
            'successful_imports_count' => $successfulImports,
            'records_total' => $recordsTotal,
            'records_processed' => $recordsTotal,
            'records_rejected' => $recordsRejected,
            'failed_imports_count' => 0,
            'created_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
        ]);
    }
}
