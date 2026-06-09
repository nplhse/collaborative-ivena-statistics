<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Media;

use App\Content\Domain\Entity\Media;
use App\Content\Infrastructure\Doctrine\MediaUploadListener;
use App\Content\Infrastructure\Media\MediaDimensionsExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Mapping\PropertyMapping;

final class MediaUploadListenerTest extends TestCase
{
    public function testPostUploadSetsImageDimensions(): void
    {
        $path = $this->createPng(150, 90);
        $media = new Media();
        $media->setFile(new File($path));
        $media->setMimeType('image/png');

        $listener = new MediaUploadListener(
            new \App\Content\Application\Media\MediaTypeResolver(),
            new MediaDimensionsExtractor(),
        );

        $listener->onPostUpload(new Event($media, new PropertyMapping('file', 'filename')));

        self::assertSame(150, $media->getWidth());
        self::assertSame(90, $media->getHeight());
    }

    private function createPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        $path = sys_get_temp_dir().'/upload-listener-'.uniqid().'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}
