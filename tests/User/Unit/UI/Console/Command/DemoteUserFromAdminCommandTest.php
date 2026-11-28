<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\UI\Console\Command;

use App\User\Application\AdministrativeUser\AdministrativeUserService;
use App\User\Application\AdministrativeUser\LastAdministratorException;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\UI\Console\Command\DemoteUserFromAdminCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\Validation;

/**
 * Every answer and branch of `app:user:demote-admin`, with a stubbed service and the real username constraints. The
 * stored roles are in the functional `DemoteUserFromAdminCommandTest`; that the service itself refuses the last
 * administrator in `DoctrineAdministrativeUserServiceTest::testDemoteThrowsForLastAdministrator` (integration).
 */
#[CoversClass(DemoteUserFromAdminCommand::class)]
final class DemoteUserFromAdminCommandTest extends TestCase
{
    /** @var list<User> */
    private array $demoted = [];

    public function testFailsWhenNotInteractive(): void
    {
        $tester = $this->tester($this->alice());

        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('must be run interactively', $this->display($tester));
        self::assertSame([], $this->demoted);
    }

    public function testDemotesAfterConfirmation(): void
    {
        $alice = $this->alice();
        $tester = $this->tester($alice);
        $tester->setInputs(['alice', 'yes']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Remove administrator privileges from "alice" (alice@example.test)?', $this->display($tester));
        self::assertStringContainsString('Removed ROLE_ADMIN from "alice".', $this->display($tester));
        self::assertSame([$alice], $this->demoted);
    }

    public function testAsksAgainForAnInvalidUsername(): void
    {
        $alice = $this->alice();
        $tester = $this->tester($alice);
        $tester->setInputs(['ab', 'alice', 'yes']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('This value is too short.', $this->display($tester));
        self::assertSame([$alice], $this->demoted);
    }

    public function testFailsForAnUnknownUsername(): void
    {
        $tester = $this->tester(null);
        $tester->setInputs(['unknown-user']);

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No user found with username "unknown-user".', $this->display($tester));
    }

    public function testAUserWithoutAdministratorPrivilegesIsLeftAlone(): void
    {
        $tester = $this->tester($this->alice()->setRoles([UserRole::USER]));
        $tester->setInputs(['alice']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('User "alice" does not have administrator privileges.', $this->display($tester));
        self::assertSame([], $this->demoted);
    }

    public function testRefusesTheLastAdministratorBeforeAskingForConfirmation(): void
    {
        $tester = $this->tester($this->alice(), administrators: 1);
        $tester->setInputs(['alice']);

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(LastAdministratorException::MESSAGE, $this->display($tester));
        self::assertStringNotContainsString('Remove administrator privileges from', $this->display($tester));
        self::assertSame([], $this->demoted);
    }

    public function testAbortDemotesNobody(): void
    {
        $tester = $this->tester($this->alice());
        $tester->setInputs(['alice', 'no']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Aborted.', $this->display($tester));
        self::assertSame([], $this->demoted);
    }

    public function testReportsWhenTheLastOtherAdministratorWentInTheMeantime(): void
    {
        $tester = $this->tester($this->alice(), demoteFails: true);
        $tester->setInputs(['alice', 'yes']);

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(LastAdministratorException::MESSAGE, $this->display($tester));
        self::assertStringNotContainsString('Removed ROLE_ADMIN', $this->display($tester));
    }

    private function alice(): User
    {
        return new User()->setUsername('alice')->setEmail('alice@example.test')->setRoles([UserRole::USER, UserRole::ADMIN]);
    }

    private function tester(?User $found, int $administrators = 2, bool $demoteFails = false): CommandTester
    {
        $service = $this->createStub(AdministrativeUserService::class);
        $service->method('findByUsername')->willReturnCallback(static fn (string $username): ?User => $username === $found?->getUserIdentifier() ? $found : null);
        $service->method('countAdministrators')->willReturn($administrators);
        $service->method('demoteFromAdmin')->willReturnCallback(function (User $user) use ($demoteFails): void {
            if ($demoteFails) {
                throw new LastAdministratorException();
            }
            $this->demoted[] = $user;
        });

        return new CommandTester(new DemoteUserFromAdminCommand($service, Validation::createValidator()));
    }

    /** The output with SymfonyStyle's line wrapping undone. */
    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
