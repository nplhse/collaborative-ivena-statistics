<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Media;

use App\Content\Application\Media\MediaDimensionsBackfillService;
use App\Content\Infrastructure\Factory\MediaFactory;
use App\Content\Infrastructure\Media\MediaStorage;
use App\Content\Infrastructure\Repository\MediaRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class MediaDimensionsBackfillServiceTest extends KernelTestCase
{
    use Factories;

    public function testDryRunReportsUpdatableMediaWithoutPersisting(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'width' => null,
            'height' => null,
        ]);

        $result = $this->backfillService()->backfill(true);

        self::assertGreaterThanOrEqual(1, $result->updated);
        self::assertNull($this->reloadMedia((int) $media->getId())->getWidth());
        self::assertNull($this->reloadMedia((int) $media->getId())->getHeight());
    }

    public function testApplyBackfillsDimensionsFromLocalFile(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'width' => null,
            'height' => null,
        ]);

        $result = $this->backfillService()->backfill(false);

        self::assertGreaterThanOrEqual(1, $result->updated);

        $reloaded = $this->reloadMedia((int) $media->getId());
        self::assertSame(1, $reloaded->getWidth());
        self::assertSame(1, $reloaded->getHeight());
    }

    public function testSkipsMediaThatAlreadyHasDimensions(): void
    {
        self::bootKernel();

        MediaFactory::createOne([
            'width' => 120,
            'height' => 80,
        ]);

        $result = $this->backfillService()->backfill(false);

        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->unknownDimensions);
    }

    public function testCountsMissingLocalFiles(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'width' => null,
            'height' => null,
        ]);

        unlink($this->storedPath($media));

        $result = $this->backfillService()->backfill(true);

        self::assertGreaterThanOrEqual(1, $result->missingFile);
        self::assertNull($this->reloadMedia((int) $media->getId())->getWidth());
    }

    public function testCountsUnknownDimensionsForUnreadableImageFile(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'width' => null,
            'height' => null,
        ]);

        file_put_contents($this->storedPath($media), 'not-an-image');

        $result = $this->backfillService()->backfill(true);

        self::assertGreaterThanOrEqual(1, $result->unknownDimensions);
        self::assertSame(0, $result->updated);
    }

    private function reloadMedia(int $mediaId): \App\Content\Domain\Entity\Media
    {
        $media = self::getContainer()->get(MediaRepository::class)->find($mediaId);
        self::assertNotNull($media);

        return $media;
    }

    private function backfillService(): MediaDimensionsBackfillService
    {
        return self::getContainer()->get(MediaDimensionsBackfillService::class);
    }

    private function storedPath(\App\Content\Domain\Entity\Media $media): string
    {
        return self::getContainer()->get(MediaStorage::class)->absolutePath((string) $media->getStorageKey());
    }
}
