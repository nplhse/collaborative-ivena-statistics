<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Repository;

use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Infrastructure\Repository\DoctrineStorageCapacityAlertStore;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineStorageCapacityAlertStoreTest extends DatabaseKernelTestCase
{
    public function testStartsAtNormalAndKeepsASingleRow(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $store = new DoctrineStorageCapacityAlertStore($entityManager);

        self::assertSame(CapacityLevel::Normal, $store->lastNotifiedLevel());

        $store->recordNotified(CapacityLevel::Warning, new \DateTimeImmutable('2026-10-24'), new \DateTimeImmutable('2026-10-24 03:31'));
        $entityManager->clear();
        self::assertSame(CapacityLevel::Warning, $store->lastNotifiedLevel());

        $store->recordNotified(CapacityLevel::Notice, new \DateTimeImmutable('2026-10-25'), new \DateTimeImmutable('2026-10-25 03:31'));
        $entityManager->clear();
        self::assertSame(CapacityLevel::Notice, $store->lastNotifiedLevel());

        $row = self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT id, last_notified_level, changed_on FROM storage_capacity_alert');
        self::assertSame([['id' => 1, 'last_notified_level' => 'notice', 'changed_on' => '2026-10-25']], $row);
    }
}
