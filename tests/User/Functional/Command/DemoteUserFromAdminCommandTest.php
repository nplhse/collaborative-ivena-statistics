<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Command;

use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use App\User\UI\Console\Command\DemoteUserFromAdminCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:user:demote-admin` with the real service and database. Every answer and branch (not interactive, invalid or
 * unknown username, no administrator, last administrator, abort) is in the unit `DemoteUserFromAdminCommandTest`.
 */
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

    private function createCommandTester(): CommandTester
    {
        /** @var DemoteUserFromAdminCommand $command */
        $command = self::getContainer()->get(DemoteUserFromAdminCommand::class);

        return new CommandTester($command);
    }
}
