<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Command;

use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use App\User\UI\Console\Command\PromoteUserToAdminCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PromoteUserToAdminCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    public function testPromotesExistingUserAfterConfirmation(): void
    {
        $user = UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
        ]);

        $tester = $this->createCommandTester();
        $tester->setInputs(['alice', 'yes']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Granted ROLE_ADMIN to "alice"', $tester->getDisplay());

        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertContains(UserRole::ADMIN, $user->getRoles());
        self::assertContains(UserRole::PARTICIPANT, $user->getRoles());
    }

    public function testAlreadyAdminIsNoOpWithoutAskingForConfirmation(): void
    {
        UserFactory::new()->asAdmin()->create([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $tester = $this->createCommandTester();
        $tester->setInputs(['alice']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('already has administrator privileges', $tester->getDisplay());
    }

    public function testUnknownUsernameFailsWithoutCreatingAUser(): void
    {
        $tester = $this->createCommandTester();
        $tester->setInputs(['unknown-user']);
        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No user found with username "unknown-user"', $tester->getDisplay());
    }

    public function testRetriesInvalidUsernameThenPromotes(): void
    {
        $user = UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $tester = $this->createCommandTester();
        $tester->setInputs(['ab', 'alice', 'yes']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Granted ROLE_ADMIN to "alice"', $tester->getDisplay());

        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertContains(UserRole::ADMIN, $user->getRoles());
    }

    public function testFailsWhenNotInteractive(): void
    {
        $tester = $this->createCommandTester();
        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('must be run interactively', $tester->getDisplay());
    }

    public function testAbortLeavesRolesUnchanged(): void
    {
        $user = UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $tester = $this->createCommandTester();
        $tester->setInputs(['alice', 'no']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Aborted', $tester->getDisplay());

        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertNotContains(UserRole::ADMIN, $user->getRoles());
    }

    private function createCommandTester(): CommandTester
    {
        /** @var PromoteUserToAdminCommand $command */
        $command = self::getContainer()->get(PromoteUserToAdminCommand::class);

        return new CommandTester($command);
    }
}
