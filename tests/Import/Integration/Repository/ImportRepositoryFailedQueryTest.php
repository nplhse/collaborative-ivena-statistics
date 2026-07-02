<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Repository;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;

final class ImportRepositoryFailedQueryTest extends DatabaseKernelTestCase
{
    private ImportRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(ImportRepository::class);
    }

    public function testFindRecentFailedImportsRespectsSinceFilter(): void
    {
        $hospital = HospitalFactory::createOne();

        ImportFactory::createOne([
            'hospital' => $hospital,
            'status' => ImportStatus::FAILED,
            'createdAt' => new \DateTimeImmutable('-5 days'),
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'status' => ImportStatus::FAILED,
            'createdAt' => new \DateTimeImmutable('-40 days'),
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('-2 days'),
        ]);

        $since = new \DateTimeImmutable('-30 days')->setTime(0, 0);
        $recent = $this->repository->findRecentFailedImports(10, $since);

        self::assertCount(1, $recent);
        self::assertSame(ImportStatus::FAILED, $recent[0]->getStatus());
        self::assertSame(2, $this->repository->countFailedImports());
    }
}
