<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Command;

use App\Content\Infrastructure\Factory\MediaFactory;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\UI\Console\Command\AnalyzePageImagesCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AnalyzePageImagesCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testDryRunShowsFindingsWithoutWritingChanges(): void
    {
        self::bootKernel();
        $this->seedPageWithAutoImage();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Analyze page images', $display);
        self::assertStringContainsString('Dry run: no database or content changes will be written.', $display);
        self::assertStringContainsString('Found 1 image reference(s)', $display);
        self::assertStringContainsString('No change', $display);
        self::assertStringContainsString('Analysis finished.', $display);
    }

    public function testApplyWithBackfillDimensionsPersistsMediaMetadata(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'command-backfill.png',
            'width' => null,
            'height' => null,
        ])->_real();

        PageFactory::createOne([
            'slug' => 'command-backfill-page',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/command-backfill.png',
                        'alt' => 'Backfill',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--apply' => true,
            '--backfill-dimensions' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Media dimension backfill', $display);
        self::assertStringContainsString('Updated media records', $display);
        self::assertStringContainsString('Analysis finished.', $display);
        self::assertStringNotContainsString('Dry run finished.', $display);

        $reloaded = MediaFactory::repository()->find($media->getId());
        self::assertNotNull($reloaded);
        self::assertSame(1, $reloaded->getWidth());
        self::assertSame(1, $reloaded->getHeight());
    }

    public function testMigrateSizeDryRunReportsPendingChanges(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'command-migrate.png',
            'width' => 605,
            'height' => 400,
        ])->_real();

        $page = PageFactory::createOne([
            'slug' => 'command-migrate-page',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/command-migrate.png',
                        'alt' => 'Migrate',
                        'size' => 'lg',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--migrate-size' => true,
            '--page-id' => (string) $page->getId(),
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Image block size migration', $display);
        self::assertStringContainsString('Updated image blocks: 1', $display);
        self::assertStringContainsString('Dry run finished. Re-run with --apply to persist changes.', $display);
        self::assertSame('lg', $page->getContent()[0]['data']['size']);
    }

    private function seedPageWithAutoImage(): void
    {
        $media = MediaFactory::createOne([
            'filename' => 'command-auto.png',
            'width' => 320,
            'height' => 200,
        ])->_real();

        PageFactory::createOne([
            'slug' => 'command-auto-page',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/command-auto.png',
                        'alt' => 'Auto',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);
    }

    private function createCommandTester(): CommandTester
    {
        $command = self::getContainer()->get(AnalyzePageImagesCommand::class);

        return new CommandTester($command);
    }
}
