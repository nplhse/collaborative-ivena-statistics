<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Command;

use App\Content\Infrastructure\Factory\MediaFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class MigrateMediaStorageCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testMovesMediaAndListsMissingFilesWithoutFailing(): void
    {
        MediaFactory::new()->legacy('command-moved.png')->create();
        MediaFactory::new()->legacy('command-missing.png')->create();
        unlink($this->legacyDir().'/command-missing.png');

        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]), 'a missing file does not stop a deploy');
        self::assertStringContainsString('1 media item(s) moved', $tester->getDisplay());
        self::assertStringContainsString('command-missing.png', $tester->getDisplay());
    }

    public function testDryRunSaysWhatWouldMoveAndMovesNothing(): void
    {
        MediaFactory::new()->legacy('command-dry.png')->create();

        $dryRun = $this->tester();
        $dryRun->execute(['--dry-run' => true]);

        self::assertStringContainsString('would move command-dry.png', $dryRun->getDisplay());
        self::assertStringContainsString('Dry run finished', $dryRun->getDisplay());

        $run = $this->tester();
        self::assertSame(Command::SUCCESS, $run->execute([]));
        self::assertStringContainsString('1 media item(s) moved', $run->getDisplay());
    }

    public function testNothingLeftToMoveSaysSo(): void
    {
        MediaFactory::createOne();

        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Every media item is in the media storage.', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester(new Application($kernel)->find('app:media:migrate-storage'));
    }

    private function legacyDir(): string
    {
        $directory = self::getContainer()->getParameter('app.media_legacy_dir');
        self::assertIsString($directory);

        return $directory;
    }
}
