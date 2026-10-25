<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Repository;

use App\Storage\Application\Contract\StorageCapacityAlertStoreInterface;
use App\Storage\Domain\Entity\StorageCapacityAlert;
use App\Storage\Domain\Enum\CapacityLevel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for StorageCapacityAlertStoreInterface.
 */
#[AsAlias(StorageCapacityAlertStoreInterface::class)]
final readonly class DoctrineStorageCapacityAlertStore implements StorageCapacityAlertStoreInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function lastNotifiedLevel(): CapacityLevel
    {
        return $this->find()?->getLastNotifiedLevel() ?? CapacityLevel::Normal;
    }

    #[\Override]
    public function recordNotified(CapacityLevel $level, \DateTimeImmutable $changedOn, \DateTimeImmutable $now): void
    {
        $alert = $this->find();

        if (!$alert instanceof StorageCapacityAlert) {
            $this->entityManager->persist(new StorageCapacityAlert($level, $changedOn, $now));
        } else {
            $alert->record($level, $changedOn, $now);
        }

        $this->entityManager->flush();
    }

    private function find(): ?StorageCapacityAlert
    {
        return $this->entityManager->find(StorageCapacityAlert::class, StorageCapacityAlert::SINGLETON_ID);
    }
}
