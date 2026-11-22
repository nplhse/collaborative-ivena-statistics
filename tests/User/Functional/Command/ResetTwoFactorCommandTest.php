<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Command;

use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ResetTwoFactorCommandTest extends KernelTestCase
{
    use Factories;

    public function testResetsAfterConfirmationAndTellsTheUser(): void
    {
        $user = $this->createUserWithTwoFactor();
        $tester = $this->createCommandTester();
        $tester->setInputs(['yes']);

        $tester->execute(['username' => 'last-admin']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Two-factor authentication of "last-admin" is switched off.', $tester->getDisplay());
        self::assertFalse($this->reload($user)->isTotpAuthenticationEnabled());
        self::assertEmailCount(1);
        self::assertSame(
            1,
            (int) self::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM audit_log WHERE intent = 'user.two_factor.reset_by_console'"),
        );
    }

    public function testDoesNothingWithoutConfirmation(): void
    {
        $user = $this->createUserWithTwoFactor();
        $tester = $this->createCommandTester();
        $tester->setInputs(['no']);

        $tester->execute(['username' => 'last-admin']);

        self::assertStringContainsString('Aborted.', $tester->getDisplay());
        self::assertTrue($this->reload($user)->isTotpAuthenticationEnabled());
        self::assertEmailCount(0);
    }

    public function testUnknownUserFails(): void
    {
        $tester = $this->createCommandTester();

        $tester->execute(['username' => 'nobody']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No user "nobody".', $tester->getDisplay());
    }

    public function testUserWithoutTwoFactorNeedsNothing(): void
    {
        UserFactory::new(['username' => 'plain-user'])->create();
        $tester = $this->createCommandTester();

        $tester->execute(['username' => 'plain-user']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('has no two-factor authentication', $tester->getDisplay());
    }

    private function createUserWithTwoFactor(): User
    {
        $user = UserFactory::new()->asAdmin()->create(['username' => 'last-admin', 'email' => 'last-admin@example.test']);
        $user->enableTotpAuthentication(
            self::getContainer()->get(TwoFactorSecretCipher::class)->encrypt('JBSWY3DPEHPK3PXP'),
            'JBSWY3DPEHPK3PXP',
            [],
            new \DateTimeImmutable('2026-11-22 12:00:00'),
        );
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    private function createCommandTester(): CommandTester
    {
        // Keeps the kernel the test already booted, so its entity manager and mailer logger stay in use.
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:user:reset-two-factor'));
    }

    private function reload(User $user): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $stored = $entityManager->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $stored);

        return $stored;
    }
}
