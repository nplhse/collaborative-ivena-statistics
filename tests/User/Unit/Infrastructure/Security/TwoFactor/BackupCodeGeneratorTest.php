<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security\TwoFactor;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\TwoFactor\BackupCodeGenerator;
use PHPUnit\Framework\TestCase;

final class BackupCodeGeneratorTest extends TestCase
{
    public function testGeneratesTenLetterCodesWithMatchingHashes(): void
    {
        $result = new BackupCodeGenerator()->generate();

        self::assertCount(10, $result['codes']);
        self::assertCount(10, array_unique($result['codes']));
        foreach ($result['codes'] as $i => $code) {
            self::assertMatchesRegularExpression('/^[A-HJ-NP-Z]{5}-[A-HJ-NP-Z]{5}$/', $code);
            self::assertTrue(password_verify(User::normalizeBackupCode($code), $result['hashes'][$i]));
        }
    }
}
