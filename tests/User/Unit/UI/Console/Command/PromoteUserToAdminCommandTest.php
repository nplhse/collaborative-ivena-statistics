<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\UI\Console\Command;

use App\User\Application\AdministrativeUser\AdministrativeUserService;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\UI\Console\Command\PromoteUserToAdminCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\Validation;

/**
 * Every answer and branch of `app:user:promote-admin`, with a stubbed service and the real username constraints. The
 * stored role is in the functional `PromoteUserToAdminCommandTest`; that promoting an administrator again changes
 * nothing in `DoctrineAdministrativeUserServiceTest::testPromoteIsNoOpWhenAlreadyAdmin` (integration).
 */
#[CoversClass(PromoteUserToAdminCommand::class)]
final class PromoteUserToAdminCommandTest extends TestCase
{
    /** @var list<User> */
    private array $promoted = [];

    public function testFailsWhenNotInteractive(): void
    {
        $tester = $this->tester($this->alice());

        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('must be run interactively', $this->display($tester));
        self::assertSame([], $this->promoted);
    }

    public function testPromotesAfterConfirmation(): void
    {
        $alice = $this->alice();
        $tester = $this->tester($alice);
        $tester->setInputs(['alice', 'yes']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Grant administrator privileges to "alice" (alice@example.test)?', $this->display($tester));
        self::assertStringContainsString('Granted ROLE_ADMIN to "alice".', $this->display($tester));
        self::assertSame([$alice], $this->promoted);
    }

    public function testConfirmationShowsADashForAUserWithoutEmail(): void
    {
        $tester = $this->tester(new User()->setUsername('alice')->setRoles([UserRole::USER]));
        $tester->setInputs(['alice', 'no']);

        $tester->execute([]);

        self::assertStringContainsString('Grant administrator privileges to "alice" (—)?', $this->display($tester));
    }

    public function testAsksAgainForAnInvalidUsername(): void
    {
        $alice = $this->alice();
        $tester = $this->tester($alice);
        $tester->setInputs(['ab', 'alice', 'yes']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('This value is too short.', $this->display($tester));
        self::assertSame([$alice], $this->promoted);
    }

    public function testFailsForAnUnknownUsername(): void
    {
        $tester = $this->tester(null);
        $tester->setInputs(['unknown-user']);

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No user found with username "unknown-user".', $this->display($tester));
    }

    public function testAnAdministratorIsLeftAloneWithoutConfirmation(): void
    {
        $tester = $this->tester($this->alice()->setRoles([UserRole::USER, UserRole::ADMIN]));
        $tester->setInputs(['alice']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('User "alice" already has administrator privileges.', $this->display($tester));
        self::assertStringNotContainsString('Grant administrator privileges to', $this->display($tester));
        self::assertSame([], $this->promoted);
    }

    public function testAbortPromotesNobody(): void
    {
        $tester = $this->tester($this->alice());
        $tester->setInputs(['alice', 'no']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Aborted.', $this->display($tester));
        self::assertSame([], $this->promoted);
    }

    private function alice(): User
    {
        return new User()->setUsername('alice')->setEmail('alice@example.test')->setRoles([UserRole::USER, UserRole::PARTICIPANT]);
    }

    private function tester(?User $found): CommandTester
    {
        $service = $this->createStub(AdministrativeUserService::class);
        $service->method('findByUsername')->willReturnCallback(static fn (string $username): ?User => $username === $found?->getUserIdentifier() ? $found : null);
        $service->method('promoteToAdmin')->willReturnCallback(function (User $user): void {
            $this->promoted[] = $user;
        });

        return new CommandTester(new PromoteUserToAdminCommand($service, Validation::createValidator()));
    }

    /** The output with SymfonyStyle's line wrapping undone. */
    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
