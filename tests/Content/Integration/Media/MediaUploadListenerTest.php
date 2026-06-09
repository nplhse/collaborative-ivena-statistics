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

        $this->listener()->onPostUpload($this->event($media));

        self::assertSame(150, $media->getWidth());
        self::assertSame(90, $media->getHeight());
    }

    public function testIgnoresNonMediaUploadObjects(): void
    {
        $this->expectNotToPerformAssertions();

        $this->listener()->onPostUpload($this->event(new \stdClass()));
    }

    public function testClearsDimensionsForNonImageUploads(): void
    {
        $media = new Media();
        $media->setMimeType('application/pdf');
        $media->setWidth(100);
        $media->setHeight(100);

        $this->listener()->onPostUpload($this->event($media));

        self::assertNull($media->getWidth());
        self::assertNull($media->getHeight());
    }

    public function testSkipsDimensionsWhenFileIsUnreadable(): void
    {
        $path = sys_get_temp_dir().'/upload-listener-invalid-'.uniqid().'.png';
        file_put_contents($path, 'not-an-image');

        $media = new Media();
        $media->setFile(new File($path));
        $media->setMimeType('image/png');

        $this->listener()->onPostUpload($this->event($media));

        self::assertNull($media->getWidth());
        self::assertNull($media->getHeight());
    }

    public function testSkipsDimensionsWhenUploadFileIsMissing(): void
    {
        $media = new Media();
        $media->setMimeType('image/png');

        $this->listener()->onPostUpload($this->event($media));

        self::assertNull($media->getWidth());
        self::assertNull($media->getHeight());
    }

    private function listener(): MediaUploadListener
    {
        return new MediaUploadListener(
            new \App\Content\Application\Media\MediaTypeResolver(),
            new MediaDimensionsExtractor(),
        );
    }

    private function event(object $object): Event
    {
        return new Event($object, new PropertyMapping('file', 'filename'));
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
