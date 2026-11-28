<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Command;

use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use App\User\UI\Console\Command\PromoteUserToAdminCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:user:promote-admin` with the real service and database. Every answer and branch (not interactive, invalid or
 * unknown username, already administrator, abort) is in the unit `PromoteUserToAdminCommandTest`.
 */
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

    private function createCommandTester(): CommandTester
    {
        /** @var PromoteUserToAdminCommand $command */
        $command = self::getContainer()->get(PromoteUserToAdminCommand::class);

        return new CommandTester($command);
    }
}
