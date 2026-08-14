<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Adapter;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Adapter\DoctrineUserImportActivityProvider;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

final class DoctrineUserImportActivityProviderTest extends DatabaseKernelTestCase
{
    public function testCountsSkipFailedImports(): void
    {
        $user = UserFactory::createOne();
        $hospital = HospitalFactory::createOne();
        $userId = $user->getId();
        self::assertNotNull($userId);

        ImportFactory::createOne([
            'createdBy' => $user,
            'hospital' => $hospital,
            'status' => ImportStatus::FAILED,
        ]);
        ImportFactory::createOne([
            'createdBy' => $user,
            'hospital' => $hospital,
            'status' => ImportStatus::COMPLETED,
        ]);
        ImportFactory::createOne([
            'createdBy' => $user,
            'hospital' => $hospital,
            'status' => ImportStatus::PARTIAL,
        ]);
        ImportFactory::createOne([
            'createdBy' => $user,
            'hospital' => $hospital,
            'status' => ImportStatus::CANCELLED,
        ]);

        $provider = self::getContainer()->get(DoctrineUserImportActivityProvider::class);

        self::assertSame(2, $provider->countsByUserIds([$userId])[$userId]);
    }
}
