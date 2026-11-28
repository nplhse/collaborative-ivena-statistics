<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Security\TwoFactor;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorReset;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resetting two-factor authentication of an account that lost its authenticator app (#1357, ADR 040): audit entry and
 * notification by mail. The administrator's button over HTTP is in `UserTwoFactorResetTest` (Admin), the console in
 * `ResetTwoFactorCommandTest`.
 */
final class TwoFactorResetTest extends DatabaseKernelTestCase
{
    public function testAResetSwitchesItOffWritesTheAuditEntryAndTellsTheUser(): void
    {
        $alice = $this->userWithTwoFactor();

        $done = self::getContainer()->get(TwoFactorReset::class)->reset($alice, TwoFactorReset::INTENT_ADMIN);

        self::assertTrue($done);
        self::assertFalse($this->reload($alice)->isTotpAuthenticationEnabled());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE intent = 'user.two_factor.reset_by_admin'"));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM notification WHERE type = 'security.two_factor_reset' AND recipient_id = ?", [$alice->getId()]));
        self::assertEmailCount(1);
    }

    public function testAnAccountWithoutTwoFactorIsLeftAlone(): void
    {
        $bob = UserFactory::new(['username' => 'bob', 'email' => 'bob@example.test'])->create();

        $done = self::getContainer()->get(TwoFactorReset::class)->reset($bob, TwoFactorReset::INTENT_ADMIN);

        self::assertFalse($done);
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE intent = 'user.two_factor.reset_by_admin'"));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM notification WHERE type = 'security.two_factor_reset'"));
        self::assertEmailCount(0);
    }

    private function userWithTwoFactor(): User
    {
        $alice = UserFactory::new(['username' => 'alice', 'email' => 'alice@example.test'])->create();
        $alice->enableTotpAuthentication(
            self::getContainer()->get(TwoFactorSecretCipher::class)->encrypt('JBSWY3DPEHPK3PXP'),
            'JBSWY3DPEHPK3PXP',
            [],
            new \DateTimeImmutable('2026-11-22 12:00:00'),
        );
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $alice;
    }

    /** @param list<mixed> $parameters */
    private function countRows(string $sql, array $parameters = []): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne($sql, $parameters);
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
