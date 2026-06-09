<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Doctrine;

use App\Content\Application\Media\MediaTypeResolver;
use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use App\Content\Infrastructure\Media\MediaDimensionsExtractor;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Vich\UploaderBundle\Event\Event;

/** @psalm-suppress UnusedClass */
final readonly class MediaUploadListener
{
    public function __construct(
        private MediaTypeResolver $mediaTypeResolver,
        private MediaDimensionsExtractor $dimensionsExtractor,
    ) {
    }

    #[AsEventListener(event: 'vich_uploader.post_upload')]
    public function onPostUpload(Event $event): void
    {
        $object = $event->getObject();
        if (!$object instanceof Media) {
            return;
        }

        $this->mediaTypeResolver->applyTo($object);
        $this->applyDimensions($object);
    }

    private function applyDimensions(Media $media): void
    {
        if (MediaType::IMAGE !== $media->getType()) {
            $media->setWidth(null);
            $media->setHeight(null);

            return;
        }

        $file = $media->getFile();
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\File) {
            return;
        }

        $dimensions = $this->dimensionsExtractor->extract($file->getPathname());
        if (!$dimensions instanceof \App\Content\Domain\ValueObject\ImageDimensions) {
            return;
        }

        $media->setWidth($dimensions->width);
        $media->setHeight($dimensions->height);
    }
}
