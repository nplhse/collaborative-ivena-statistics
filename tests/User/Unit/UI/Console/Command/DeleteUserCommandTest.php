<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\UI\Console\Command;

use App\Allocation\Domain\Entity\Hospital;
use App\User\Application\AdministrativeUser\AdministrativeUserService;
use App\User\Application\AdministrativeUser\UserNotDeletableException;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\UI\Console\Command\DeleteUserCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\Validation;

/**
 * Every answer and refusal of `app:user:delete`, with a stubbed service and the real username constraints. The deletion
 * in the database is in the functional `DeleteUserCommandTest`; that the service itself refuses the last administrator,
 * a hospital owner and a user other records still reference is in `DoctrineAdministrativeUserServiceTest` (integration).
 */
#[CoversClass(DeleteUserCommand::class)]
final class DeleteUserCommandTest extends TestCase
{
    /** @var list<User> */
    private array $deleted = [];

    public function testFailsWhenNotInteractive(): void
    {
        $tester = $this->tester($this->alice());

        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('must be run interactively', $this->display($tester));
        self::assertSame([], $this->deleted);
    }

    public function testDeletesAfterConfirmation(): void
    {
        $alice = $this->alice();
        $tester = $this->tester($alice);
        $tester->setInputs(['alice', 'yes']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Delete user "alice" (alice@example.test)? This cannot be undone.', $this->display($tester));
        self::assertStringContainsString('Deleted user "alice".', $this->display($tester));
        self::assertSame([$alice], $this->deleted);
    }

    public function testAsksAgainForAnInvalidUsername(): void
    {
        $alice = $this->alice();
        $tester = $this->tester($alice);
        $tester->setInputs(['ab', 'alice', 'yes']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('This value is too short.', $this->display($tester));
        self::assertSame([$alice], $this->deleted);
    }

    public function testFailsForAnUnknownUsername(): void
    {
        $tester = $this->tester(null);
        $tester->setInputs(['unknown-user']);

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No user found with username "unknown-user".', $this->display($tester));
    }

    public function testRefusesTheLastAdministratorBeforeAskingForConfirmation(): void
    {
        $tester = $this->tester($this->alice()->setRoles([UserRole::USER, UserRole::ADMIN]), administrators: 1);
        $tester->setInputs(['alice']);

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(UserNotDeletableException::LAST_ADMINISTRATOR, $this->display($tester));
        self::assertStringNotContainsString('This cannot be undone', $this->display($tester));
        self::assertSame([], $this->deleted);
    }

    public function testRefusesAHospitalOwnerBeforeAskingForConfirmation(): void
    {
        $tester = $this->tester($this->alice()->addHospital(new Hospital()));
        $tester->setInputs(['alice']);

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(UserNotDeletableException::OWNS_HOSPITALS, $this->display($tester));
        self::assertStringNotContainsString('This cannot be undone', $this->display($tester));
        self::assertSame([], $this->deleted);
    }

    public function testAnotherAdministratorMayBeDeleted(): void
    {
        $alice = $this->alice()->setRoles([UserRole::USER, UserRole::ADMIN]);
        $tester = $this->tester($alice, administrators: 2);
        $tester->setInputs(['alice', 'yes']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([$alice], $this->deleted);
    }

    public function testAbortDeletesNothing(): void
    {
        $tester = $this->tester($this->alice());
        $tester->setInputs(['alice', 'no']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Aborted.', $this->display($tester));
        self::assertSame([], $this->deleted);
    }

    public function testReportsWhenOtherRecordsStillReferenceTheUser(): void
    {
        $tester = $this->tester($this->alice(), deleteFails: UserNotDeletableException::referenced());
        $tester->setInputs(['alice', 'yes']);

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(UserNotDeletableException::REFERENCED, $this->display($tester));
        self::assertStringNotContainsString('Deleted user', $this->display($tester));
    }

    private function alice(): User
    {
        return new User()->setUsername('alice')->setEmail('alice@example.test')->setRoles([UserRole::USER]);
    }

    /** The output with SymfonyStyle's line wrapping undone. */
    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function tester(?User $found, int $administrators = 1, ?UserNotDeletableException $deleteFails = null): CommandTester
    {
        $service = $this->createStub(AdministrativeUserService::class);
        $service->method('findByUsername')->willReturnCallback(static fn (string $username): ?User => $username === $found?->getUserIdentifier() ? $found : null);
        $service->method('countAdministrators')->willReturn($administrators);
        $service->method('delete')->willReturnCallback(function (User $user) use ($deleteFails): void {
            if ($deleteFails instanceof UserNotDeletableException) {
                throw $deleteFails;
            }
            $this->deleted[] = $user;
        });

        return new CommandTester(new DeleteUserCommand($service, Validation::createValidator()));
    }
}
