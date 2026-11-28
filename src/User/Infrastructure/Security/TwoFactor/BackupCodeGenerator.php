<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\TwoFactor;

use App\User\Domain\Entity\User;

/**
 * One-time backup codes for two-factor authentication (#1357): ten codes of ten letters (about 46 bits each), shown
 * once as "ABCDE-FGHJK" and stored as password hashes. Letters only, so they never look like a TOTP code.
 */
final class BackupCodeGenerator
{
    public const int COUNT = 10;

    /** Without I and O, which are easily confused with 1 and 0. */
    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const int LENGTH = 10;

    /**
     * @param int|null $hashCost bcrypt cost; null keeps PHP's default. Only the test environment lowers it
     *                           (`app.two_factor.backup_code_hash_cost`), like the password hashers in security.yaml.
     *
     * @return array{codes: list<string>, hashes: list<string>}
     */
    public function generate(?int $hashCost = null): array
    {
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < self::COUNT; ++$i) {
            $code = '';
            for ($j = 0; $j < self::LENGTH; ++$j) {
                $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
            }

            $codes[] = substr($code, 0, 5).'-'.substr($code, 5);
            $hashes[] = password_hash(
                User::normalizeBackupCode($code),
                \PASSWORD_DEFAULT,
                null === $hashCost ? [] : ['cost' => $hashCost],
            );
        }

        return ['codes' => $codes, 'hashes' => $hashes];
    }
}
