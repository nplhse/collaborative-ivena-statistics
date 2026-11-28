<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Command;

use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Repository\UserRepository;
use App\User\UI\Console\Command\CreateUserCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:user:create` with the real service, database and password hasher. Every answer and refusal (not interactive,
 * invalid username or email, identity taken, weak or mismatched password) is in the unit `CreateUserCommandTest`; a
 * user created without administrator privileges in `DoctrineAdministrativeUserServiceTest::testCreatePersistsEnabledVerifiedUser`.
 */
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

    public function testCreatesAnEnabledVerifiedAdministrator(): void
    {
        /** @var CreateUserCommand $command */
        $command = self::getContainer()->get(CreateUserCommand::class);
        $tester = new CommandTester($command);
        $tester->setInputs(['alice', 'alice@example.test', self::PLAIN_PASSWORD, self::PLAIN_PASSWORD, 'yes']);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Created user "alice" <alice@example.test> (admin: yes).', $tester->getDisplay());
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'alice']);
        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->isEnabled());
        self::assertTrue($user->isVerified());
        self::assertFalse($user->isCredentialsExpired());
        self::assertSame('alice@example.test', $user->getEmail());
        self::assertContains(UserRole::ADMIN, $user->getRoles());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, self::PLAIN_PASSWORD));
    }
}
