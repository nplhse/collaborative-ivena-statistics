<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Doctrine;

use App\Content\Domain\Entity\Media;
use App\Content\Infrastructure\Media\LocalMediaFileLocator;
use App\Content\Infrastructure\Media\MediaStorage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Removes the files of deleted media items and the old file of a replaced one, after the commit (ADR 039).
 *
 * The files are collected while Doctrine computes the changes and removed in postFlush, which runs only after a
 * successful commit, so a failed flush never leaves a row without its file. A file that cannot be removed stays as an
 * orphan for the integrity check. A media item not yet moved from the old upload directory takes its old file along,
 * as VichUploader did.
 *
 * @psalm-suppress UnusedClass Registered through its Doctrine listener attributes.
 */
#[AsDoctrineListener(event: Events::onFlush, connection: 'default')]
#[AsDoctrineListener(event: Events::postFlush, connection: 'default')]
final class MediaFileRemovalListener
{
    /** @var list<string> */
    private array $storageKeys = [];

    /** @var list<string> */
    private array $legacyPaths = [];

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly LocalMediaFileLocator $fileLocator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->storageKeys = [];
        $this->legacyPaths = [];
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if (!$entity instanceof Media) {
                continue;
            }

            $this->collect($entity->getStorageKey(), null === $entity->getStorageKey() ? $entity->getFilename() : null);
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Media) {
                continue;
            }

            $changes = $unitOfWork->getEntityChangeSet($entity);
            $previousKey = $changes['storageKey'][0] ?? null;
            if (\is_string($previousKey) && $previousKey !== $entity->getStorageKey()) {
                $this->collect($previousKey, null);
            }
        }
    }

    /** @psalm-suppress PossiblyUnusedParam Doctrine passes the event arguments. */
    public function postFlush(): void
    {
        $storageKeys = $this->storageKeys;
        $legacyPaths = $this->legacyPaths;
        $this->storageKeys = [];
        $this->legacyPaths = [];

        foreach ($storageKeys as $storageKey) {
            try {
                $this->storage->delete($storageKey);
            } catch (\Throwable $exception) {
                $this->logger->warning('Could not remove the media file {key}; the integrity check removes it later.', ['key' => $storageKey, 'exception' => $exception]);
            }
        }

        if ([] !== $legacyPaths) {
            try {
                new Filesystem()->remove($legacyPaths);
            } catch (\Throwable $exception) {
                $this->logger->warning('Could not remove an old media file.', ['exception' => $exception]);
            }
        }
    }

    private function collect(?string $storageKey, ?string $legacyFilename): void
    {
        if (null !== $storageKey && MediaStorage::isValidKey($storageKey)) {
            $this->storageKeys[] = $storageKey;
        }

        $legacyPath = null !== $legacyFilename ? $this->fileLocator->resolveLegacyPath($legacyFilename) : null;
        if (null !== $legacyPath) {
            $this->legacyPaths[] = $legacyPath;
        }
    }
}
