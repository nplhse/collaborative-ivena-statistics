<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

use App\Content\Domain\Enum\MediaType;
use App\Content\Domain\ValueObject\ImageDimensions;
use App\Content\Infrastructure\Media\LocalMediaFileLocator;
use App\Content\Infrastructure\Media\MediaDimensionsExtractor;
use App\Content\Infrastructure\Repository\MediaRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MediaDimensionsBackfillService
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private MediaRepository $mediaRepository,
        private LocalMediaFileLocator $fileLocator,
        private MediaDimensionsExtractor $dimensionsExtractor,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function backfill(bool $dryRun): MediaDimensionsBackfillResult
    {
        $updated = 0;
        $missingFile = 0;
        $unknownDimensions = 0;

        foreach ($this->mediaRepository->findByType(MediaType::IMAGE) as $media) {
            if (null !== $media->getWidth() && null !== $media->getHeight()) {
                continue;
            }

            $absolutePath = $this->fileLocator->resolveAbsolutePath($media);
            if (null === $absolutePath || !is_file($absolutePath)) {
                ++$missingFile;
                continue;
            }

            $dimensions = $this->dimensionsExtractor->extract($absolutePath);
            if (!$dimensions instanceof ImageDimensions) {
                ++$unknownDimensions;
                continue;
            }

            if (!$dryRun) {
                $media->setWidth($dimensions->width);
                $media->setHeight($dimensions->height);
            }

            ++$updated;
        }

        if (!$dryRun && $updated > 0) {
            $this->entityManager->flush();
        }

        return new MediaDimensionsBackfillResult($updated, $missingFile, $unknownDimensions);
    }
}

final readonly class MediaDimensionsBackfillResult
{
    public function __construct(
        public int $updated,
        public int $missingFile,
        public int $unknownDimensions,
    ) {
    }
}
