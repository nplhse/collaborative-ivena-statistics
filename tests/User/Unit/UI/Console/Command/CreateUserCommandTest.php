<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\UI\Console\Command;

use App\User\Application\AdministrativeUser\AdministrativeUserService;
use App\User\Application\AdministrativeUser\AdministrativeUserValidationException;
use App\User\Application\AdministrativeUser\IdentityTakenException;
use App\User\Domain\Entity\User;
use App\User\UI\Console\Command\CreateUserCommand;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CreateUserCommandTest extends TestCase
{
    public function testReturnsFailureWhenCreateReportsIdentityTaken(): void
    {
        $service = $this->createMock(AdministrativeUserService::class);
        $service->expects(self::once())->method('isIdentityTaken')->willReturn(false);
        $service->expects(self::once())->method('create')->willThrowException(new IdentityTakenException());

        $io = $this->createInteractiveIo('alice', 'alice@example.test');
        $io->expects(self::once())->method('error')->with(IdentityTakenException::MESSAGE);

        $status = new CreateUserCommand($service, $this->acceptingValidator())(
            $io,
            $this->interactiveInput(),
        );

        self::assertSame(Command::FAILURE, $status);
    }

    public function testReturnsFailureWhenCreateReportsValidationErrors(): void
    {
        $service = $this->createMock(AdministrativeUserService::class);
        $service->expects(self::once())->method('isIdentityTaken')->willReturn(false);
        $service->expects(self::once())->method('create')->willThrowException(
            new AdministrativeUserValidationException(['Password is too weak.']),
        );

        $io = $this->createInteractiveIo('alice', 'alice@example.test');
        $io->expects(self::once())->method('error')->with('Password is too weak.');

        $status = new CreateUserCommand($service, $this->acceptingValidator())(
            $io,
            $this->interactiveInput(),
        );

        self::assertSame(Command::FAILURE, $status);
    }

    public function testAskValidatedRejectsInvalidAnswersThenAcceptsRetry(): void
    {
        $created = new User()->setUsername('alice')->setEmail('alice@example.test');

        $service = $this->createMock(AdministrativeUserService::class);
        $service->expects(self::once())->method('isIdentityTaken')->willReturn(false);
        $service->expects(self::once())->method('create')->willReturn($created);

        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturnCallback(
            static function (mixed $value): ConstraintViolationList {
                if ('ab' === $value || 'not-an-email' === $value) {
                    return new ConstraintViolationList([
                        new ConstraintViolation('Invalid value.', null, [], $value, null, $value),
                    ]);
                }

                return new ConstraintViolationList();
            },
        );

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects(self::exactly(2))->method('ask')->willReturnCallback(
            static function (string $question, mixed $default, ?callable $validatorFn): string {
                self::assertIsCallable($validatorFn);
                unset($default);

                if ('Username' === $question) {
                    try {
                        $validatorFn('ab');
                        self::fail('Expected the short username to be rejected.');
                    } catch (\InvalidArgumentException) {
                    }

                    $username = $validatorFn('alice');
                    self::assertIsString($username);

                    return $username;
                }

                try {
                    $validatorFn('not-an-email');
                    self::fail('Expected the invalid email to be rejected.');
                } catch (\InvalidArgumentException) {
                }

                $email = $validatorFn('alice@example.test');
                self::assertIsString($email);

                return $email;
            },
        );
        $io->expects(self::exactly(2))->method('askHidden')->willReturn('super-secret-password');
        $io->expects(self::once())->method('confirm')->willReturn(false);
        $io->expects(self::once())->method('success');

        $status = new CreateUserCommand($service, $validator)($io, $this->interactiveInput());

        self::assertSame(Command::SUCCESS, $status);
    }

    public function testThrowsWhenUsernameAnswerIsNotAString(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects(self::once())->method('ask')->willReturn(null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Expected a string answer.');

        new CreateUserCommand(
            $this->createStub(AdministrativeUserService::class),
            $this->acceptingValidator(),
        )($io, $this->interactiveInput());
    }

    public function testThrowsWhenPasswordAnswerIsNotAString(): void
    {
        $service = $this->createStub(AdministrativeUserService::class);
        $service->method('isIdentityTaken')->willReturn(false);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects(self::exactly(2))->method('ask')->willReturnOnConsecutiveCalls('alice', 'alice@example.test');
        $io->expects(self::once())->method('askHidden')->willReturn(null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Expected a string password.');

        new CreateUserCommand($service, $this->acceptingValidator())($io, $this->interactiveInput());
    }

    /**
     * @return MockObject&SymfonyStyle
     */
    private function createInteractiveIo(string $username, string $email): SymfonyStyle
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('ask')->willReturnOnConsecutiveCalls($username, $email);
        $io->method('askHidden')->willReturn('super-secret-password');
        $io->method('confirm')->willReturn(false);

        return $io;
    }

    private function interactiveInput(): InputInterface
    {
        $input = $this->createStub(InputInterface::class);
        $input->method('isInteractive')->willReturn(true);

        return $input;
    }

    private function acceptingValidator(): ValidatorInterface&Stub
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        return $validator;
    }
}
