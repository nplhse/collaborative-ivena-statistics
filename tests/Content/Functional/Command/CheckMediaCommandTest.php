<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Command;

use App\Content\Infrastructure\Factory\MediaFactory;
use App\Content\Infrastructure\Media\MediaStorage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class CheckMediaCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        new Filesystem()->remove(self::getContainer()->get(MediaStorage::class)->root());
    }

    public function testIntactFilesPass(): void
    {
        MediaFactory::createMany(2);

        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('The media files match the media library.', $tester->getDisplay());
    }

    public function testAMissingFileFailsAndIsNamed(): void
    {
        $media = MediaFactory::createOne();
        unlink(self::getContainer()->get(MediaStorage::class)->absolutePath((string) $media->getStorageKey()));

        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--full' => true]));
        self::assertStringContainsString((string) $media->getStorageKey(), $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester(new Application($kernel)->find('app:media:check'));
    }
}
