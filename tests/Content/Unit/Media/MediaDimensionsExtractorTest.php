<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Media;

use App\Content\Infrastructure\Media\MediaDimensionsExtractor;
use PHPUnit\Framework\TestCase;

final class MediaDimensionsExtractorTest extends TestCase
{
    public function testExtractsDimensionsFromValidPng(): void
    {
        $path = $this->createPng(120, 80);

        $dimensions = $this->extractor()->extract($path);

        self::assertNotNull($dimensions);
        self::assertSame(120, $dimensions->width);
        self::assertSame(80, $dimensions->height);
    }

    public function testReturnsNullForMissingFile(): void
    {
        self::assertNull($this->extractor()->extract('/tmp/does-not-exist-'.uniqid().'.png'));
    }

    public function testReturnsNullForInvalidFile(): void
    {
        $path = sys_get_temp_dir().'/invalid-image-'.uniqid().'.png';
        file_put_contents($path, 'not-an-image');

        self::assertNull($this->extractor()->extract($path));
    }

    private function extractor(): MediaDimensionsExtractor
    {
        return new MediaDimensionsExtractor();
    }

    private function createPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        $path = sys_get_temp_dir().'/extractor-test-'.uniqid().'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}
