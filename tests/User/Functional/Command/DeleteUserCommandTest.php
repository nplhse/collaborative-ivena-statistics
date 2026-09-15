<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Command;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\UserRepository;
use App\User\UI\Console\Command\DeleteUserCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DeleteUserCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    public function testDeletesUserAfterConfirmation(): void
    {
        UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $tester = $this->createCommandTester();
        $tester->setInputs(['alice', 'yes']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Deleted user "alice"', $tester->getDisplay());
        self::assertNull($this->getUserRepository()->findOneBy(['username' => 'alice']));
    }

    public function testUnknownUsernameFails(): void
    {
        $tester = $this->createCommandTester();
        $tester->setInputs(['unknown-user']);
        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No user found with username "unknown-user"', $tester->getDisplay());
    }

    public function testAbortLeavesUserUnchanged(): void
    {
        UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $tester = $this->createCommandTester();
        $tester->setInputs(['alice', 'no']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Aborted', $tester->getDisplay());
        self::assertInstanceOf(User::class, $this->getUserRepository()->findOneBy(['username' => 'alice']));
    }

    public function testRefusesToDeleteUserWhoOwnsAHospital(): void
    {
        $user = UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);
        HospitalFactory::createOne([
            'owner' => $user,
            'name' => 'Owned Hospital',
        ]);

        $tester = $this->createCommandTester();
        $tester->setInputs(['alice']);
        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('still owns hospital', $tester->getDisplay());
        self::assertInstanceOf(User::class, $this->getUserRepository()->findOneBy(['username' => 'alice']));
    }

    public function testRefusesToDeleteTheLastAdministrator(): void
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
        self::assertInstanceOf(User::class, $this->getUserRepository()->findOneBy(['username' => 'alice']));
    }

    public function testRefusesWhenOtherRecordsStillReferenceTheUser(): void
    {
        $target = UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);
        $owner = UserFactory::createOne([
            'username' => 'owner',
            'email' => 'owner@example.test',
        ]);
        HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $target,
            'name' => 'Referenced Hospital',
        ]);

        $tester = $this->createCommandTester();
        $tester->setInputs(['alice', 'yes']);
        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('other records still', $tester->getDisplay());
        self::assertInstanceOf(User::class, $this->getUserRepository()->findOneBy(['username' => 'alice']));
    }

    public function testFailsWhenNotInteractive(): void
    {
        UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $tester = $this->createCommandTester();
        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('must be run interactively', $tester->getDisplay());
        self::assertInstanceOf(User::class, $this->getUserRepository()->findOneBy(['username' => 'alice']));
    }

    private function getUserRepository(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = self::getContainer()->get(UserRepository::class);

        return $repository;
    }

    private function createCommandTester(): CommandTester
    {
        /** @var DeleteUserCommand $command */
        $command = self::getContainer()->get(DeleteUserCommand::class);

        return new CommandTester($command);
    }
}
