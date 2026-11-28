<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Domain\Validator;

use App\User\Domain\Validator\UserPasswordConstraints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
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

        self::assertSame(
            [NotBlank::class, Length::class, PasswordStrength::class, NotCompromisedPassword::class],
            array_map(static fn (object $constraint): string => $constraint::class, $constraints),
        );
        self::assertInstanceOf(Length::class, $constraints[1]);
        self::assertSame([8, 4096], [$constraints[1]->min, $constraints[1]->max]);
        self::assertInstanceOf(PasswordStrength::class, $constraints[2]);
        self::assertSame(PasswordStrength::STRENGTH_WEAK, $constraints[2]->minScore);
    }

    /**
     * Pins Symfony's scoring: the strength meter in assets/controllers/password_strength_controller.js re-implements
     * it to show the same verdict as the server, and has no test of its own. A Symfony update that changes these
     * scores has to be copied there.
     */
    #[DataProvider('estimateStrengthProvider')]
    public function testEstimateStrength(string $password, int $expectedStrength): void
    {
        $strength = PasswordStrengthValidator::estimateStrength($password);

        self::assertSame($expectedStrength, $strength);
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
