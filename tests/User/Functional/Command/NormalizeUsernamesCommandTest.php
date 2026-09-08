<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Command;

use App\User\Domain\Factory\UserFactory;
use App\User\UI\Console\Command\NormalizeUsernamesCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class NormalizeUsernamesCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testDryRunDoesNotRenameAndApplyPersistsUniqueReplacement(): void
    {
        $valid = UserFactory::createOne(['username' => 'already-valid']);
        $withSpace = UserFactory::createOne(['username' => 'John Doe']);

        $tester = $this->createCommandTester();
        $dryRun = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $dryRun);
        $display = $tester->getDisplay();
        self::assertStringContainsString(NormalizeUsernamesCommand::STATUS_WOULD_RENAME, $display);
        self::assertStringContainsString('John Doe', $display);
        self::assertStringContainsString('John-Doe', $display);
        self::assertStringContainsString('Dry run: no rows will be written.', $display);
        self::assertStringNotContainsString('already-valid', $display);

        \Zenstruck\Foundry\Persistence\refresh($withSpace);
        self::assertSame('John Doe', $withSpace->getUsername());

        $apply = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $apply);
        self::assertStringContainsString(NormalizeUsernamesCommand::STATUS_RENAMED, $tester->getDisplay());

        \Zenstruck\Foundry\Persistence\refresh($withSpace);
        \Zenstruck\Foundry\Persistence\refresh($valid);
        self::assertSame('John-Doe', $withSpace->getUsername());
        self::assertSame('already-valid', $valid->getUsername());
    }

    public function testCollisionIsSkippedAndStillInvalidNamesAreReported(): void
    {
        $taken = UserFactory::createOne(['username' => 'John-Doe']);
        $withSpace = UserFactory::createOne(['username' => 'John Doe']);
        $invalid = UserFactory::createOne(['username' => 'foo@bar']);

        $tester = $this->createCommandTester();
        $apply = $tester->execute(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $apply);
        $display = $tester->getDisplay();
        self::assertStringContainsString(NormalizeUsernamesCommand::STATUS_COLLISION, $display);
        self::assertStringContainsString(NormalizeUsernamesCommand::STATUS_STILL_INVALID, $display);

        \Zenstruck\Foundry\Persistence\refresh($taken);
        \Zenstruck\Foundry\Persistence\refresh($withSpace);
        \Zenstruck\Foundry\Persistence\refresh($invalid);
        self::assertSame('John-Doe', $taken->getUsername());
        self::assertSame('John Doe', $withSpace->getUsername());
        self::assertSame('foo@bar', $invalid->getUsername());
    }

    private function createCommandTester(): CommandTester
    {
        /** @var NormalizeUsernamesCommand $command */
        $command = self::getContainer()->get(NormalizeUsernamesCommand::class);

        return new CommandTester($command);
    }
}
