<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Media;

use App\Content\Infrastructure\Media\MediaStorage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Each ParaTest worker has its own media storage (TEST_TOKEN, like its test database), because integrity check tests
 * empty the storage root.
 */
final class MediaStoragePerWorkerTest extends KernelTestCase
{
    public function testTheStorageRootCarriesTheTokenOfTheWorker(): void
    {
        self::bootKernel();
        $token = getenv('TEST_TOKEN');

        $root = self::getContainer()->get(MediaStorage::class)->root();

        self::assertStringEndsWith('/media'.(false === $token ? '' : $token), $root);
        self::assertStringEndsWith('legacy-media'.(false === $token ? '' : $token), (string) self::getContainer()->getParameter('app.media_legacy_dir'));
    }
}
