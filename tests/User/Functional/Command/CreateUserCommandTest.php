<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Command;

use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Repository\UserRepository;
use App\User\UI\Console\Command\CreateUserCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class CreateUserCommandTest extends KernelTestCase
{
    use Factories;

    private const string PLAIN_PASSWORD = 'super-secret-password';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    public function testCreatesEnabledVerifiedUserWithoutAdminByDefault(): void
    {
        $tester = $this->createCommandTester();
        $tester->setInputs([
            'alice',
            'alice@example.test',
            self::PLAIN_PASSWORD,
            self::PLAIN_PASSWORD,
            'no',
        ]);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('Created user "alice"', $display);
        self::assertStringContainsString('admin: no', $display);
        self::assertStringNotContainsString(self::PLAIN_PASSWORD, $display);

        $user = $this->findUser('alice');
        self::assertTrue($user->isEnabled());
        self::assertTrue($user->isVerified());
        self::assertFalse($user->isCredentialsExpired());
        self::assertSame('alice@example.test', $user->getEmail());
        self::assertNotContains(UserRole::ADMIN, $user->getRoles());

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, self::PLAIN_PASSWORD));
    }

    public function testCreatesAdministratorWhenConfirmed(): void
    {
        $tester = $this->createCommandTester();
        $tester->setInputs([
            'bob-admin',
            'bob-admin@example.test',
            self::PLAIN_PASSWORD,
            self::PLAIN_PASSWORD,
            'yes',
        ]);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('admin: yes', $tester->getDisplay());
        self::assertStringNotContainsString(self::PLAIN_PASSWORD, $tester->getDisplay());

        $user = $this->findUser('bob-admin');
        self::assertContains(UserRole::ADMIN, $user->getRoles());
    }

    public function testRejectsDuplicateIdentityWithoutCreatingASecondUser(): void
    {
        UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $tester = $this->createCommandTester();
        $tester->setInputs([
            'alice',
            'other@example.test',
        ]);
        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('already taken', $tester->getDisplay());
        self::assertSame(1, $this->getUserRepository()->count(['username' => 'alice']));
    }

    public function testRejectsWeakPasswordThenCreatesUserOnRetry(): void
    {
        $tester = $this->createCommandTester();
        $tester->setInputs([
            'carol',
            'carol@example.test',
            'short',
            self::PLAIN_PASSWORD,
            self::PLAIN_PASSWORD,
            'no',
        ]);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertInstanceOf(User::class, $this->getUserRepository()->findOneBy(['username' => 'carol']));
        self::assertStringNotContainsString(self::PLAIN_PASSWORD, $tester->getDisplay());
    }

    public function testRetriesInvalidUsernameAndEmailThenCreatesUser(): void
    {
        $tester = $this->createCommandTester();
        $tester->setInputs([
            'ab',
            'dave',
            'not-an-email',
            'dave@example.test',
            self::PLAIN_PASSWORD,
            self::PLAIN_PASSWORD,
            'no',
        ]);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertInstanceOf(User::class, $this->getUserRepository()->findOneBy(['username' => 'dave']));
    }

    public function testRetriesWhenPasswordConfirmationDoesNotMatch(): void
    {
        $tester = $this->createCommandTester();
        $tester->setInputs([
            'erin',
            'erin@example.test',
            self::PLAIN_PASSWORD,
            'does-not-match-password',
            self::PLAIN_PASSWORD,
            self::PLAIN_PASSWORD,
            'no',
        ]);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('do not match', $tester->getDisplay());
        self::assertStringNotContainsString(self::PLAIN_PASSWORD, $tester->getDisplay());
        self::assertInstanceOf(User::class, $this->getUserRepository()->findOneBy(['username' => 'erin']));
    }

    public function testFailsWhenNotInteractive(): void
    {
        $tester = $this->createCommandTester();
        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('must be run interactively', $tester->getDisplay());
        self::assertSame(0, $this->getUserRepository()->count([]));
    }

    private function findUser(string $username): User
    {
        $user = $this->getUserRepository()->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function getUserRepository(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = self::getContainer()->get(UserRepository::class);

        return $repository;
    }

    private function createCommandTester(): CommandTester
    {
        /** @var CreateUserCommand $command */
        $command = self::getContainer()->get(CreateUserCommand::class);

        return new CommandTester($command);
    }
}
