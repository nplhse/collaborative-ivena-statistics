<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Media;

use App\Content\Application\Contract\MediaFileLookupInterface;
use App\Content\Application\Media\DeliverableMediaFile;
use App\Content\Application\Media\MediaPublicUrlResolver;
use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaVisibility;
use App\Content\Infrastructure\Repository\MediaRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Database and storage side of the media delivery (ADR 039). A row whose file is missing is logged, so the delivery
 * answers 404 instead of failing; the integrity check reports it as well.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for MediaFileLookupInterface.
 */
#[AsAlias(MediaFileLookupInterface::class)]
final readonly class MediaFileLookup implements MediaFileLookupInterface
{
    public function __construct(
        private MediaRepository $mediaRepository,
        private LocalMediaFileLocator $fileLocator,
        private MediaPublicUrlResolver $publicUrlResolver,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function findStoredFile(string $storageKey): ?DeliverableMediaFile
    {
        $media = $this->mediaRepository->findOneByStorageKey($storageKey);
        if (!$media instanceof Media || !$this->isDeliverable($media)) {
            return null;
        }

        return $this->deliverable($media, $this->fileLocator->resolveAbsolutePathFromStorageKey($storageKey), true);
    }

    #[\Override]
    public function findMovedUrl(string $legacyFilename): ?string
    {
        $media = $this->mediaRepository->findOneByLegacyFilename($legacyFilename);
        if (!$media instanceof Media || null === $media->getStorageKey() || !$this->isDeliverable($media)) {
            return null;
        }

        return $this->publicUrlResolver->resolve($media);
    }

    #[\Override]
    public function findLegacyFile(string $legacyFilename): ?DeliverableMediaFile
    {
        $media = $this->mediaRepository->findOneByLegacyFilename($legacyFilename);
        if (!$media instanceof Media || null !== $media->getStorageKey() || !$this->isDeliverable($media)) {
            return null;
        }

        return $this->deliverable($media, $this->fileLocator->resolveLegacyPath($legacyFilename), false);
    }

    private function isDeliverable(Media $media): bool
    {
        return MediaVisibility::Public === $media->getVisibility();
    }

    private function deliverable(Media $media, ?string $path, bool $immutable): ?DeliverableMediaFile
    {
        if (null === $path || !is_file($path)) {
            $this->logger->warning('The file of media item {id} is missing.', ['id' => $media->getId(), 'file' => $media->getStoredName()]);

            return null;
        }

        return new DeliverableMediaFile(
            $path,
            $media->getMimeType() ?? 'application/octet-stream',
            $media->getOriginalFilename() ?? basename($path),
            $immutable,
        );
    }
}
