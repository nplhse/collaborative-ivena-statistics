<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PageImageContentMigrationService;
use App\Content\Infrastructure\Factory\MediaFactory;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Repository\PageRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PageImageContentMigrationServiceTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testDryRunDoesNotPersistSizeMigration(): void
    {
        self::bootKernel();

        $page = $this->createPageWithLargeImageBlock();
        $pageId = (int) $page->getId();

        $result = $this->migrationService()->migrateSizes(true, $pageId);

        self::assertSame(1, $result->updatedBlocks);
        self::assertSame(1, $result->updatedPages);
        self::assertSame('lg', $this->reloadPage($pageId)->getContent()[0]['data']['size']);
    }

    public function testApplyMigratesLargeImageBlocksToAuto(): void
    {
        self::bootKernel();

        $page = $this->createPageWithLargeImageBlock();
        $pageId = (int) $page->getId();

        $result = $this->migrationService()->migrateSizes(false, $pageId);

        self::assertSame(1, $result->updatedBlocks);
        self::assertSame('auto', $this->reloadPage($pageId)->getContent()[0]['data']['size']);
    }

    public function testFixRichtextSnippetsReplacesLegacySizeClass(): void
    {
        self::bootKernel();

        $page = PageFactory::createOne([
            'slug' => 'migration-richtext',
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<figure class="page-content-image page-content-image--size-lg"></figure>',
                    ],
                ],
            ],
        ])->_real();

        $pageId = (int) $page->getId();
        $result = $this->migrationService()->fixRichtextSnippets(false, $pageId);

        self::assertSame(1, $result->updatedBlocks);
        self::assertSame(1, $result->updatedPages);
        self::assertStringContainsString(
            'page-content-image--size-auto',
            $this->reloadPage($pageId)->getContent()[0]['data']['html'],
        );
        self::assertStringNotContainsString(
            'page-content-image--size-lg',
            $this->reloadPage($pageId)->getContent()[0]['data']['html'],
        );
    }

    public function testSkipsFloatedLargeImagesDuringSizeMigration(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'floated.png',
            'width' => 400,
            'height' => 300,
        ])->_real();

        $page = PageFactory::createOne([
            'slug' => 'migration-floated-lg',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/floated.png',
                        'alt' => 'Floated',
                        'size' => 'lg',
                        'float' => 'left',
                    ],
                ],
            ],
        ])->_real();

        $pageId = (int) $page->getId();
        $result = $this->migrationService()->migrateSizes(false, $pageId);

        self::assertSame(0, $result->updatedBlocks);
        self::assertSame('lg', $this->reloadPage($pageId)->getContent()[0]['data']['size']);
    }

    private function createPageWithLargeImageBlock(): \App\Content\Domain\Entity\Page
    {
        $media = MediaFactory::createOne([
            'filename' => 'migrate-me.png',
            'width' => 605,
            'height' => 400,
        ])->_real();

        return PageFactory::createOne([
            'slug' => 'migration-lg-image',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/migrate-me.png',
                        'alt' => 'Migrate me',
                        'size' => 'lg',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();
    }

    private function reloadPage(int $pageId): \App\Content\Domain\Entity\Page
    {
        $page = self::getContainer()->get(PageRepository::class)->find($pageId);
        self::assertNotNull($page);

        return $page;
    }

    private function migrationService(): PageImageContentMigrationService
    {
        return self::getContainer()->get(PageImageContentMigrationService::class);
    }
}
