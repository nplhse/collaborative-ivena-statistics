<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Domain\Validator;

use App\User\Domain\Validator\UserPasswordConstraints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Symfony\Component\Validator\Constraints\PasswordStrengthValidator;

final class UserPasswordConstraintsTest extends TestCase
{
    public function testClientConfigMatchesPolicyConstants(): void
    {
        self::assertSame([
            'minLength' => UserPasswordConstraints::MIN_LENGTH,
            'maxLength' => UserPasswordConstraints::MAX_LENGTH,
            'minStrengthScore' => UserPasswordConstraints::MIN_STRENGTH_SCORE,
            'strengthLevelCount' => PasswordStrength::STRENGTH_VERY_STRONG + 1,
        ], UserPasswordConstraints::clientConfig());
    }

    public function testForPlainPasswordUsesPolicyConstants(): void
    {
        $constraints = UserPasswordConstraints::forPlainPassword();

        self::assertCount(4, $constraints);
    }

    #[DataProvider('estimateStrengthProvider')]
    public function testEstimateStrength(string $password, int $expectedStrength): void
    {
        self::assertSame($expectedStrength, PasswordStrengthValidator::estimateStrength($password));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function estimateStrengthProvider(): iterable
    {
        yield 'empty password' => ['', PasswordStrength::STRENGTH_VERY_WEAK];
        yield 'short password' => ['short', PasswordStrength::STRENGTH_VERY_WEAK];
        yield 'numeric password' => ['12345678', PasswordStrength::STRENGTH_VERY_WEAK];
        yield 'strong passphrase' => ['super-secret-password', PasswordStrength::STRENGTH_STRONG];
    }
}
