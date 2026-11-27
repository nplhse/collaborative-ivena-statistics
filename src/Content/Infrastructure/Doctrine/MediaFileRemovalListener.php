<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Doctrine;

use App\Content\Domain\Entity\Media;
use App\Content\Infrastructure\Media\MediaStorage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Removes the files of deleted media items and the old file of a replaced one, after the commit (ADR 039).
 *
 * The files are collected while Doctrine computes the changes and removed in postFlush, which runs only after a
 * successful commit, so a failed flush never leaves a row without its file. A file that cannot be removed stays as an
 * orphan for the integrity check.
 *
 * @psalm-suppress UnusedClass Registered through its Doctrine listener attributes.
 */
#[AsDoctrineListener(event: Events::onFlush, connection: 'default')]
#[AsDoctrineListener(event: Events::postFlush, connection: 'default')]
final class MediaFileRemovalListener
{
    /** @var list<string> */
    private array $storageKeys = [];

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->storageKeys = [];
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if (!$entity instanceof Media) {
                continue;
            }

            $this->collect($entity->getStorageKey());
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Media) {
                continue;
            }

            $changes = $unitOfWork->getEntityChangeSet($entity);
            $previousKey = $changes['storageKey'][0] ?? null;
            if (\is_string($previousKey) && $previousKey !== $entity->getStorageKey()) {
                $this->collect($previousKey);
            }
        }
    }

    /** @psalm-suppress PossiblyUnusedParam Doctrine passes the event arguments. */
    public function postFlush(): void
    {
        $storageKeys = $this->storageKeys;
        $this->storageKeys = [];

        foreach ($storageKeys as $storageKey) {
            try {
                $this->storage->delete($storageKey);
            } catch (\Throwable $exception) {
                $this->logger->warning('Could not remove the media file {key}; the integrity check removes it later.', ['key' => $storageKey, 'exception' => $exception]);
            }
        }
    }

    private function collect(?string $storageKey): void
    {
        if (null !== $storageKey && MediaStorage::isValidKey($storageKey)) {
            $this->storageKeys[] = $storageKey;
        }
    }
}
