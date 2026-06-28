<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Repository;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

final class ImportRepositoryOnboardingQueryTest extends DatabaseKernelTestCase
{
    private ImportRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(ImportRepository::class);
    }

    public function testHasImportsCreatedByUserSinceReturnsTrueForRecentImport(): void
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'createdBy' => $user,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
        ]);
        ImportFactory::createOne([
            'createdBy' => $user,
            'hospital' => $hospital,
            'createdAt' => new \DateTimeImmutable('-2 months'),
        ]);

        self::assertTrue($this->repository->hasImportsCreatedByUserSince(
            $user,
            new \DateTimeImmutable('-6 months'),
        ));
    }

    public function testHasImportsCreatedByUserSinceReturnsFalseForOldImport(): void
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'createdBy' => $user,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
        ]);
        ImportFactory::createOne([
            'createdBy' => $user,
            'hospital' => $hospital,
            'createdAt' => new \DateTimeImmutable('-8 months'),
        ]);

        self::assertFalse($this->repository->hasImportsCreatedByUserSince(
            $user,
            new \DateTimeImmutable('-6 months'),
        ));
    }
}
