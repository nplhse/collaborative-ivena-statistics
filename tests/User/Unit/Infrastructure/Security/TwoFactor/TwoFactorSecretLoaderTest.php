<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security\TwoFactor;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Decrypting the TOTP secret after a user is loaded (#1357). What the entity does with a missing secret (an unusable
 * stand-in, backup codes still work) is in `UserTwoFactorTest`; a backup code sign-in over HTTP in
 * `TwoFactorLoginTest::testBackupCodeWorksOnce`.
 */
#[CoversClass(TwoFactorSecretLoader::class)]
final class TwoFactorSecretLoaderTest extends TestCase
{
    private const string KEY = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    /** @var list<array{string, string, array<mixed>}> */
    private array $logs = [];

    public function testTheSecretIsDecryptedWithTheConfiguredKey(): void
    {
        $user = $this->loadedUser(new TwoFactorSecretCipher(self::KEY)->encrypt(self::SECRET));

        $this->loader()->postLoad($user);

        self::assertSame(self::SECRET, $user->getTotpAuthenticationConfiguration()?->getSecret());
        self::assertSame([], $this->logs);
    }

    public function testAnUndecryptableSecretGetsTheStandInAndIsLogged(): void
    {
        // Encrypted with another key, as after a lost or changed APP_TWO_FACTOR_KEY.
        $user = $this->loadedUser(new TwoFactorSecretCipher(str_repeat('ab', 32))->encrypt(self::SECRET));

        $this->loader()->postLoad($user);

        self::assertTrue($user->isTotpAuthenticationEnabled());
        self::assertNotSame(self::SECRET, $user->getTotpAuthenticationConfiguration()?->getSecret());
        self::assertSame([['error', 'security.two_factor.secret_undecryptable', ['user_id' => 7]]], $this->logs);
    }

    public function testAnAccountWithoutTwoFactorIsLeftAlone(): void
    {
        $user = new User()->setUsername('alice');

        $this->loader()->postLoad($user);

        self::assertNull($user->getTotpAuthenticationConfiguration());
        self::assertSame([], $this->logs);
    }

    private function loader(): TwoFactorSecretLoader
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
            $this->logs[] = ['error', (string) $message, $context];
        });

        return new TwoFactorSecretLoader(new TwoFactorSecretCipher(self::KEY), $logger);
    }

    /** A user as Doctrine hands it over: encrypted secret stored, plain secret not yet revealed. */
    private function loadedUser(string $encryptedSecret): User
    {
        $user = new User()->setUsername('alice');
        new \ReflectionProperty(User::class, 'id')->setValue($user, 7);
        $user->enableTotpAuthentication($encryptedSecret, 'placeholder', [], new \DateTimeImmutable('2026-11-22 12:00:00'));
        $user->revealTotpSecret(null);

        return $user;
    }
}
