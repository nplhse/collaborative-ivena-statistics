<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security\TwoFactor;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\TwoFactor\BackupCodeGenerator;
use PHPUnit\Framework\TestCase;

final class BackupCodeGeneratorTest extends TestCase
{
    /** The lowest bcrypt cost, as in the test environment; the default cost makes ten hashes take seconds. */
    private const int HASH_COST = 4;

    public function testGeneratesTenDifferentCodesInTheDisplayFormat(): void
    {
        $result = new BackupCodeGenerator(self::HASH_COST)->generate();

        self::assertCount(10, $result['codes']);
        self::assertCount(10, array_unique($result['codes']));
        self::assertContainsOnlyString($result['codes']);
        self::assertSame([], preg_grep('/^[A-HJ-NP-Z]{5}-[A-HJ-NP-Z]{5}$/', $result['codes'], \PREG_GREP_INVERT), 'five letters, dash, five letters, no I or O');
    }

    public function testEachHashVerifiesItsOwnCode(): void
    {
        $result = new BackupCodeGenerator(self::HASH_COST)->generate();

        self::assertTrue(password_verify(User::normalizeBackupCode($result['codes'][0]), $result['hashes'][0]));
        self::assertFalse(password_verify(User::normalizeBackupCode($result['codes'][1]), $result['hashes'][0]));
    }

    public function testTheConfiguredCostIsUsed(): void
    {
        $result = new BackupCodeGenerator(self::HASH_COST)->generate();

        self::assertSame(self::HASH_COST, password_get_info($result['hashes'][0])['options']['cost']);
    }
}
