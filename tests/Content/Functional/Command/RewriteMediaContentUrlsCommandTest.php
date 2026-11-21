<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Command;

use App\Content\Infrastructure\Factory\PostFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class RewriteMediaContentUrlsCommandTest extends KernelTestCase
{
    use Factories;

    public function testListsOldUrlsWithoutMovedMediaAndSucceeds(): void
    {
        $kernel = self::bootKernel();
        PostFactory::createOne(['content' => '<p><img src="/uploads/media/never-moved.png"></p>']);

        $tester = new CommandTester(new Application($kernel)->find('app:media:rewrite-content-urls'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('never-moved.png', $tester->getDisplay());
        self::assertStringContainsString('No old media URL left to replace.', $tester->getDisplay());
    }
}
