<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\UI\Console\Command;

use App\User\Application\AdministrativeUser\AdministrativeUserService;
use App\User\Application\AdministrativeUser\AdministrativeUserValidationException;
use App\User\Application\AdministrativeUser\CreateUserInput;
use App\User\Application\AdministrativeUser\IdentityTakenException;
use App\User\Domain\Entity\User;
use App\User\UI\Console\Command\CreateUserCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Validator\Constraints\NotCompromisedPasswordValidator;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;

/**
 * Every answer and branch of `app:user:create`, with a stubbed service and the real constraints. That the user is stored
 * enabled and verified with a valid password hash is in the functional `CreateUserCommandTest`; how the service
 * validates and maps a unique constraint race is in `DoctrineAdministrativeUserServiceTest` (integration).
 */
#[CoversClass(CreateUserCommand::class)]
final class CreateUserCommandTest extends TestCase
{
    private const string PASSWORD = 'super-secret-password';

    /** @var list<CreateUserInput> */
    private array $created = [];

    public function testFailsWhenNotInteractive(): void
    {
        $tester = $this->tester();

        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('must be run interactively', $this->display($tester));
        self::assertSame([], $this->created);
    }

    public function testCreatesTheUserFromTheNormalisedAnswers(): void
    {
        $tester = $this->tester();
        $tester->setInputs([' alice ', ' Alice@Example.TEST ', self::PASSWORD, self::PASSWORD, 'yes']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertEquals([new CreateUserInput('alice', 'alice@example.test', self::PASSWORD, true)], $this->created);
        self::assertStringContainsString('Created user "alice" <alice@example.test> (admin: yes).', $this->display($tester));
        self::assertStringNotContainsString(self::PASSWORD, $this->display($tester));
    }

    public function testAsksAgainForAnInvalidUsernameAndEmail(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['ab', 'alice', 'not-an-email', 'alice@example.test', self::PASSWORD, self::PASSWORD, 'no']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('This value is too short.', $this->display($tester));
        self::assertStringContainsString('This value is not a valid email address.', $this->display($tester));
        self::assertEquals([new CreateUserInput('alice', 'alice@example.test', self::PASSWORD, false)], $this->created);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function passwordRetries(): iterable
    {
        // password answers before the admin question, message shown before the retry
        yield 'too weak' => [['short', self::PASSWORD, self::PASSWORD], 'This value is too short.'];
        yield 'confirmation differs' => [[self::PASSWORD, 'does-not-match-password', self::PASSWORD, self::PASSWORD], 'The password and confirmation do not match.'];
    }

    /**
     * @param list<string> $passwordAnswers
     */
    #[DataProvider('passwordRetries')]
    public function testAsksAgainForThePassword(array $passwordAnswers, string $message): void
    {
        $tester = $this->tester();
        $tester->setInputs(['alice', 'alice@example.test', ...$passwordAnswers, 'no']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString($message, $this->display($tester));
        self::assertSame(self::PASSWORD, $this->created[0]->plainPassword);
        self::assertStringNotContainsString(self::PASSWORD, $this->display($tester));
    }

    public function testStopsBeforeThePasswordWhenTheIdentityIsTaken(): void
    {
        $tester = $this->tester(identityTaken: true);
        $tester->setInputs(['alice', 'alice@example.test']);

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(IdentityTakenException::MESSAGE, $this->display($tester));
        self::assertStringNotContainsString('Password', $this->display($tester));
        self::assertSame([], $this->created);
    }

    /** @return iterable<string, array{\RuntimeException, string}> */
    public static function createFailures(): iterable
    {
        yield 'identity taken in the meantime' => [new IdentityTakenException(), IdentityTakenException::MESSAGE];
        yield 'rejected by the service' => [new AdministrativeUserValidationException(['Password is too weak.']), 'Password is too weak.'];
    }

    #[DataProvider('createFailures')]
    public function testReportsWhenTheServiceRefusesToCreate(\RuntimeException $exception, string $message): void
    {
        $tester = $this->tester(createFails: $exception);
        $tester->setInputs(['alice', 'alice@example.test', self::PASSWORD, self::PASSWORD, 'no']);

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString($message, $this->display($tester));
        self::assertStringNotContainsString('Created user', $this->display($tester));
    }

    /** The output with SymfonyStyle's line wrapping undone. */
    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function tester(bool $identityTaken = false, ?\RuntimeException $createFails = null): CommandTester
    {
        $service = $this->createStub(AdministrativeUserService::class);
        $service->method('isIdentityTaken')->willReturn($identityTaken);
        $service->method('create')->willReturnCallback(function (CreateUserInput $input) use ($createFails): User {
            if ($createFails instanceof \RuntimeException) {
                throw $createFails;
            }
            $this->created[] = $input;

            return new User()->setUsername($input->username)->setEmail($input->email);
        });

        // The password constraints include NotCompromisedPassword; switched off as in the test environment.
        $validator = Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([
                NotCompromisedPasswordValidator::class => new NotCompromisedPasswordValidator(new MockHttpClient(), 'UTF-8', false),
            ]))
            ->getValidator();

        return new CommandTester(new CreateUserCommand($service, $validator));
    }
}
