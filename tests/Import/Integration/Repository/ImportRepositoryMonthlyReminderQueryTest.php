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

final class ImportRepositoryMonthlyReminderQueryTest extends DatabaseKernelTestCase
{
    private ImportRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(ImportRepository::class);
    }

    public function testFindLatestSuccessfulByHospitalReturnsMostRecentCompletedImport(): void
    {
        [$hospital, $user] = $this->seedHospital();

        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => new \DateTimeImmutable('2026-03-01'),
            'status' => ImportStatus::FAILED,
        ]);
        $latest = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => new \DateTimeImmutable('2026-05-15'),
            'status' => ImportStatus::COMPLETED,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => new \DateTimeImmutable('2026-04-01'),
            'status' => ImportStatus::PARTIAL,
        ]);

        $found = $this->repository->findLatestSuccessfulByHospital((int) $hospital->getId());

        self::assertNotNull($found);
        self::assertSame($latest->getId(), $found->getId());
    }

    public function testCountImportsByMonthInRangeBucketsImportsChronologically(): void
    {
        [$hospital, $user] = $this->seedHospital();

        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => new \DateTimeImmutable('2026-04-10'),
            'status' => ImportStatus::COMPLETED,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => new \DateTimeImmutable('2026-05-12'),
            'status' => ImportStatus::COMPLETED,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => new \DateTimeImmutable('2026-05-20'),
            'status' => ImportStatus::FAILED,
        ]);

        $rows = $this->repository->countImportsByMonthInRange(
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-06-01'),
        );

        self::assertSame([
            ['year' => 2026, 'month' => 4, 'count' => 1],
            ['year' => 2026, 'month' => 5, 'count' => 2],
        ], $rows);
    }

    public function testMonthlySubmissionStatusForHospitalTracksSuccessFailedAndMissing(): void
    {
        [$hospital, $user] = $this->seedHospital();
        $monthKeys = ['2026-03', '2026-04', '2026-05'];

        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => new \DateTimeImmutable('2026-03-05'),
            'status' => ImportStatus::COMPLETED,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => new \DateTimeImmutable('2026-05-05'),
            'status' => ImportStatus::FAILED,
        ]);

        $status = $this->repository->monthlySubmissionStatusForHospital(
            (int) $hospital->getId(),
            $monthKeys,
            new \DateTimeImmutable('2026-03-01'),
        );

        self::assertSame([
            '2026-03' => 'success',
            '2026-04' => 'missing',
            '2026-05' => 'failed',
        ], $status);
    }

    /**
     * @return array{0: object, 1: object}
     */
    private function seedHospital(): array
    {
        $user = UserFactory::createOne([
            'email' => sprintf('import-repo-%s@example.test', bin2hex(random_bytes(4))),
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
