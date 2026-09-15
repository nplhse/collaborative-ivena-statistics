<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\UI\Console\Command;

use App\User\Application\AdministrativeUser\AdministrativeUserService;
use App\User\Application\AdministrativeUser\UserNotDeletableException;
use App\User\Domain\Entity\User;
use App\User\UI\Console\Command\DeleteUserCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class DeleteUserCommandTest extends TestCase
{
    public function testReturnsFailureWhenDeleteReportsTheUserIsStillReferenced(): void
    {
        $user = new User()->setUsername('alice');

        $service = $this->createMock(AdministrativeUserService::class);
        $service->method('findByUsername')->with('alice')->willReturn($user);
        $service->method('delete')->willThrowException(UserNotDeletableException::referenced());

        $io = $this->createMock(SymfonyStyle::class);
        $io->method('ask')->willReturn('alice');
        $io->method('confirm')->willReturn(true);
        $io->expects(self::once())->method('error')->with(UserNotDeletableException::REFERENCED);

        $status = new DeleteUserCommand($service, $this->acceptingValidator())(
            $io,
            $this->interactiveInput(),
        );

        self::assertSame(Command::FAILURE, $status);
    }

    public function testReturnsFailureWhenUsernameAnswerIsNotAString(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('ask')->willReturn(null);
        $io->expects(self::once())->method('error')->with('No user found with the given username.');

        $status = new DeleteUserCommand(
            $this->createStub(AdministrativeUserService::class),
            $this->acceptingValidator(),
        )($io, $this->interactiveInput());

        self::assertSame(Command::FAILURE, $status);
    }

    private function interactiveInput(): InputInterface
    {
        $input = $this->createStub(InputInterface::class);
        $input->method('isInteractive')->willReturn(true);

        return $input;
    }

    private function acceptingValidator(): ValidatorInterface
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        return $validator;
    }
}
