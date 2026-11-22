<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\TwoFactor;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts TOTP secrets at rest with libsodium and `APP_TWO_FACTOR_KEY` (#1357, ADR 040), so a database dump or backup
 * (which never contains `.env.local`, ADR 038) does not reveal them. Without a valid key two-factor authentication is
 * not offered.
 */
final readonly class TwoFactorSecretCipher
{
    private const string VERSION_PREFIX = 'v1:';

    /** @psalm-suppress PossiblyUnusedMethod Built by the container. */
    public function __construct(
        #[Autowire(env: 'APP_TWO_FACTOR_KEY')]
        private string $key,
    ) {
    }

    /** True for a key of 64 hex characters (32 bytes). */
    public function isConfigured(): bool
    {
        return 1 === preg_match('/^[0-9a-fA-F]{64}$/', $this->key);
    }

    public function encrypt(string $secret): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::VERSION_PREFIX.base64_encode($nonce.sodium_crypto_secretbox($secret, $nonce, $this->binaryKey()));
    }

    /** Null when the key is missing or does not match. */
    public function decrypt(string $encrypted): ?string
    {
        if (!$this->isConfigured() || !str_starts_with($encrypted, self::VERSION_PREFIX)) {
            return null;
        }

        $payload = base64_decode(substr($encrypted, \strlen(self::VERSION_PREFIX)), true);
        if (false === $payload || \strlen($payload) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $secret = sodium_crypto_secretbox_open(
            substr($payload, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($payload, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->binaryKey(),
        );

        return false === $secret ? null : $secret;
    }

    private function binaryKey(): string
    {
        if (!$this->isConfigured()) {
            throw new \LogicException('APP_TWO_FACTOR_KEY must be 64 hex characters to encrypt two-factor secrets.');
        }

        return (string) hex2bin($this->key);
    }
}
