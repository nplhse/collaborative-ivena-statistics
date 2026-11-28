<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Domain\Entity;

use App\User\Domain\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function normalisedBackupCodes(): iterable
    {
        yield 'lower case with hyphen' => ['abcde-fghjk'];
        yield 'upper case with spaces around and inside' => [' ABCDE FGHJK '];
        yield 'as stored' => ['ABCDEFGHJK'];
    }

    #[DataProvider('normalisedBackupCodes')]
    public function testBackupCodeIgnoresCaseAndSeparators(string $code): void
    {
        $user = $this->enabledUser();

        $isBackupCode = $user->isBackupCode($code);

        self::assertTrue($isBackupCode);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBackupCodes(): iterable
    {
        yield 'one letter off' => ['ABCDE-FGHJL'];
        // Six digits are a TOTP code; backup codes contain letters only.
        yield 'TOTP code' => ['123456'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidBackupCodes')]
    public function testWrongOrDigitCodeIsNoBackupCode(string $code): void
    {
        $user = $this->enabledUser();

        $isBackupCode = $user->isBackupCode($code);

        self::assertFalse($isBackupCode);
    }

    public function testUsedBackupCodeIsInvalidAndTheOtherStays(): void
    {
        $user = $this->enabledUser();

        $user->invalidateBackupCode('abcde-fghjk');

        self::assertFalse($user->isBackupCode('ABCDEFGHJK'));
        self::assertSame(1, $user->countBackupCodes());
        self::assertTrue($user->isBackupCode('ZYXWVUTSRQ'));
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
            // Lowest bcrypt cost: the default cost makes every hash and every check take about 0.25 s.
            [password_hash('ABCDEFGHJK', \PASSWORD_BCRYPT, ['cost' => 4]), password_hash('ZYXWVUTSRQ', \PASSWORD_BCRYPT, ['cost' => 4])],
            new \DateTimeImmutable('2026-11-22 12:00:00'),
        );

        return $user;
    }
}
