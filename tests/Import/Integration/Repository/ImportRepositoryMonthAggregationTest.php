<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Repository;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

final class ImportRepositoryMonthAggregationTest extends DatabaseKernelTestCase
{
    private ImportRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(ImportRepository::class);
    }

    public function testCountByMonthLast12MonthsAggregatesInWindowOnly(): void
    {
        [$hospital, $user] = $this->seedHospital();

        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        $monthA = $from->modify('+10 days');
        $monthB = $from->modify('+1 month +10 days');
        $outOfWindow = $from->modify('-1 day');

        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => $monthA,
            'status' => ImportStatus::COMPLETED,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => $monthA->modify('+3 days'),
            'status' => ImportStatus::COMPLETED,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => $monthB,
            'status' => ImportStatus::FAILED,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => $outOfWindow,
            'status' => ImportStatus::COMPLETED,
        ]);

        $rows = $this->repository->countByMonthLast12Months();

        self::assertSame([
            ['year' => (int) $monthA->format('Y'), 'month' => (int) $monthA->format('n'), 'count' => 2],
            ['year' => (int) $monthB->format('Y'), 'month' => (int) $monthB->format('n'), 'count' => 1],
        ], $rows);
    }

    /**
     * @return array{0: object, 1: object}
     */
    private function seedHospital(): array
    {
        $user = UserFactory::createOne([
            'email' => sprintf('import-month-agg-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
        ]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'owner' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        return [$hospital, $user];
    }
}
