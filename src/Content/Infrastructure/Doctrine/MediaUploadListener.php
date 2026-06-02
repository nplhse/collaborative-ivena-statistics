<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Doctrine;

use App\Content\Application\Media\MediaTypeResolver;
use App\Content\Domain\Entity\Media;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Vich\UploaderBundle\Event\Event;

/** @psalm-suppress UnusedClass */
final readonly class MediaUploadListener
{
    public function __construct(
        private MediaTypeResolver $mediaTypeResolver,
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
    }
}
