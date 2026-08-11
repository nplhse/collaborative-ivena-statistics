<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Command;

use App\Content\Domain\Entity\Page;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Repository\PageRepository;
use App\Content\UI\Console\Command\BackfillPageTranslationsCommand;
use App\Shared\Application\Locale\SupportedLocales;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BackfillPageTranslationsCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testDryRunShowsNoteAndDoesNotPersist(): void
    {
        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'title' => 'Command Dry',
            'slug' => 'command-dry',
            'path' => '/command-dry',
            'status' => Page::STATUS_PUBLISHED,
        ]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Backfill page translations', $display);
        self::assertStringContainsString('Dry run: no database changes will be written.', $display);
        self::assertStringContainsString('Dry run completed successfully.', $display);
        self::assertStringContainsString('Created translations', $display);

        /** @var PageRepository $pages */
        $pages = self::getContainer()->get(PageRepository::class);
        $reloaded = $pages->find($page->getId());
        self::assertInstanceOf(Page::class, $reloaded);
        self::assertFalse($reloaded->hasTranslation(SupportedLocales::DEFAULT));
    }

    public function testBackfillPersistsAndReportsSuccess(): void
    {
        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'title' => 'Command Live',
            'slug' => 'command-live',
            'path' => '/command-live',
            'status' => Page::STATUS_PUBLISHED,
        ]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Backfill completed successfully.', $display);
        self::assertStringContainsString('Content default locale:', $display);
        self::assertStringNotContainsString('Dry run completed successfully.', $display);

        /** @var PageRepository $pages */
        $pages = self::getContainer()->get(PageRepository::class);
        $reloaded = $pages->find($page->getId());
        self::assertInstanceOf(Page::class, $reloaded);
        self::assertTrue($reloaded->hasTranslation(SupportedLocales::DEFAULT));
    }

    public function testBackfillReportsFailureWhenLegacyFieldsMissing(): void
    {
        PageFactory::new()->withoutDefaultTranslation()->create([
            'title' => '',
            'slug' => 'command-missing-title',
            'path' => '/command-missing-title',
            'status' => Page::STATUS_DRAFT,
        ]);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Errors', $display);
        self::assertStringContainsString('missing legacy title/slug/path', $display);
        self::assertStringContainsString('Completed with', $display);
    }

    private function createCommandTester(): CommandTester
    {
        $command = self::getContainer()->get(BackfillPageTranslationsCommand::class);

        return new CommandTester($command);
    }
}
