<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Command;

use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use App\User\UI\Console\Command\DemoteUserFromAdminCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DemoteUserFromAdminCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    public function testDemotesAdminAndKeepsOtherRoles(): void
    {
        UserFactory::new()->asAdmin()->create([
            'username' => 'other-admin',
            'email' => 'other-admin@example.test',
        ]);
        $user = UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
            'roles' => [UserRole::USER, UserRole::ADMIN, UserRole::PARTICIPANT],
        ]);

        $tester = $this->createCommandTester();
        $tester->setInputs(['alice', 'yes']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Removed ROLE_ADMIN from "alice"', $tester->getDisplay());

        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertNotContains(UserRole::ADMIN, $user->getRoles());
        self::assertContains(UserRole::PARTICIPANT, $user->getRoles());
    }

    public function testNonAdminIsNoOp(): void
    {
        UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $tester = $this->createCommandTester();
        $tester->setInputs(['alice']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('does not have administrator privileges', $tester->getDisplay());
    }

    public function testUnknownUsernameFails(): void
    {
        $tester = $this->createCommandTester();
        $tester->setInputs(['unknown-user']);
        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No user found with username "unknown-user"', $tester->getDisplay());
    }

    public function testRefusesToDemoteTheLastAdministrator(): void
    {
        UserFactory::new()->asAdmin()->create([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $tester = $this->createCommandTester();
        $tester->setInputs(['alice']);
        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('last remaining administrator', $tester->getDisplay());
    }

    private function createCommandTester(): CommandTester
    {
        /** @var DemoteUserFromAdminCommand $command */
        $command = self::getContainer()->get(DemoteUserFromAdminCommand::class);

        return new CommandTester($command);
    }
}
