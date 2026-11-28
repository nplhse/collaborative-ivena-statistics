<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security\TwoFactor;

use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TwoFactorSecretCipherTest extends TestCase
{
    private const string KEY = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    public function testEncryptedSecretIsVersionedAndHidesThePlainText(): void
    {
        $cipher = new TwoFactorSecretCipher(self::KEY);

        $encrypted = $cipher->encrypt('JBSWY3DPEHPK3PXP');

        self::assertStringStartsWith('v1:', $encrypted);
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', $encrypted);
    }

    public function testEncryptedSecretDecryptsWithTheSameKey(): void
    {
        $encrypted = new TwoFactorSecretCipher(self::KEY)->encrypt('JBSWY3DPEHPK3PXP');

        $decrypted = new TwoFactorSecretCipher(self::KEY)->decrypt($encrypted);

        self::assertSame('JBSWY3DPEHPK3PXP', $decrypted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherKeys(): iterable
    {
        yield 'another valid key' => [str_repeat('ab', 32)];
        yield 'no key' => [''];
    }

    #[DataProvider('otherKeys')]
    public function testEncryptedSecretDoesNotDecryptWithAnotherKey(string $key): void
    {
        $encrypted = new TwoFactorSecretCipher(self::KEY)->encrypt('JBSWY3DPEHPK3PXP');

        $decrypted = new TwoFactorSecretCipher($key)->decrypt($encrypted);

        self::assertNull($decrypted);
    }

    public function testEveryEncryptionUsesAFreshNonce(): void
    {
        $cipher = new TwoFactorSecretCipher(self::KEY);

        self::assertNotSame($cipher->encrypt('JBSWY3DPEHPK3PXP'), $cipher->encrypt('JBSWY3DPEHPK3PXP'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenPayloads(): iterable
    {
        yield 'no prefix' => ['abc'];
        yield 'not base64' => ['v1:%%%'];
        yield 'too short' => ['v1:'.base64_encode('short')];
    }

    #[DataProvider('brokenPayloads')]
    public function testBrokenPayloadsDecryptToNull(string $payload): void
    {
        self::assertNull(new TwoFactorSecretCipher(self::KEY)->decrypt($payload));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function keys(): iterable
    {
        yield 'valid' => [self::KEY, true];
        yield 'empty' => ['', false];
        yield 'too short' => ['0011', false];
        yield 'not hex' => [str_repeat('zz', 32), false];
    }

    #[DataProvider('keys')]
    public function testOnlyA32ByteHexKeyCountsAsConfigured(string $key, bool $configured): void
    {
        $isConfigured = new TwoFactorSecretCipher($key)->isConfigured();

        self::assertSame($configured, $isConfigured);
    }

    public function testEncryptingWithoutAKeyFails(): void
    {
        $this->expectException(\LogicException::class);

        new TwoFactorSecretCipher('')->encrypt('JBSWY3DPEHPK3PXP');
    }
}
