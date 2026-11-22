<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Domain\Entity;

use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTwoFactorTest extends TestCase
{
    public function testTwoFactorIsOffUntilEnabled(): void
    {
        $user = new User()->setUsername('alice');

        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertNull($user->getTotpAuthenticationConfiguration());
    }

    public function testEnabledAccountUsesItsDecryptedSecret(): void
    {
        $user = $this->enabledUser();

        self::assertTrue($user->isTotpAuthenticationEnabled());
        self::assertSame('alice', $user->getTotpAuthenticationUsername());
        self::assertSame('JBSWY3DPEHPK3PXP', $user->getTotpAuthenticationConfiguration()?->getSecret());
    }

    public function testUndecryptableSecretStaysEnabledWithAnUnusableStandIn(): void
    {
        $user = $this->enabledUser();
        $user->revealTotpSecret(null);

        self::assertTrue($user->isTotpAuthenticationEnabled());
        $secret = $user->getTotpAuthenticationConfiguration()?->getSecret();
        self::assertNotNull($secret);
        self::assertMatchesRegularExpression('/^[A-Z2-7]{20}$/', $secret);
        self::assertNotSame('JBSWY3DPEHPK3PXP', $secret);
    }

    public function testBackupCodeIgnoresCaseAndSeparatorsAndWorksOnce(): void
    {
        $user = $this->enabledUser();

        self::assertTrue($user->isBackupCode('abcde-fghjk'));
        self::assertTrue($user->isBackupCode(' ABCDE FGHJK '));
        self::assertFalse($user->isBackupCode('ABCDE-FGHJL'));
        self::assertFalse($user->isBackupCode('123456'));
        self::assertFalse($user->isBackupCode(''));

        $user->invalidateBackupCode('abcde-fghjk');
        self::assertFalse($user->isBackupCode('ABCDEFGHJK'));
        self::assertSame(1, $user->countBackupCodes());
    }

    public function testDisablingRemovesSecretAndBackupCodes(): void
    {
        $user = $this->enabledUser();

        $user->disableTotpAuthentication();

        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertNull($user->getTotpSecretEncrypted());
        self::assertNull($user->getTotpEnabledAt());
        self::assertSame(0, $user->countBackupCodes());
    }

    public function testDecryptedSecretIsNotSerialized(): void
    {
        $serialized = serialize($this->enabledUser());

        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', $serialized);
        self::assertStringContainsString('v1:encrypted', $serialized);
    }

    private function enabledUser(): User
    {
        $user = new User()->setUsername('alice');
        $user->enableTotpAuthentication(
            'v1:encrypted',
            'JBSWY3DPEHPK3PXP',
            [password_hash('ABCDEFGHJK', \PASSWORD_DEFAULT), password_hash('ZYXWVUTSRQ', \PASSWORD_DEFAULT)],
            new \DateTimeImmutable('2026-11-22 12:00:00'),
        );

        return $user;
    }
}
