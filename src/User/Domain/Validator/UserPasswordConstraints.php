<?php

declare(strict_types=1);

namespace App\User\Domain\Validator;

use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\PasswordStrength;

final class UserPasswordConstraints
{
    public const int MIN_LENGTH = 8;

    public const int MAX_LENGTH = 4096;

    public const int MIN_STRENGTH_SCORE = PasswordStrength::STRENGTH_WEAK;

    /**
     * Moderate password rules: min length, basic strength, breached-password check.
     *
     * @return list<NotBlank|Length|PasswordStrength|NotCompromisedPassword>
     */
    public static function forPlainPassword(): array
    {
        return [
            new NotBlank(),
            new Length(min: self::MIN_LENGTH, max: self::MAX_LENGTH),
            new PasswordStrength(minScore: self::MIN_STRENGTH_SCORE),
            new NotCompromisedPassword(),
        ];
    }

    /**
     * @return array{minLength: int, maxLength: int, minStrengthScore: int, strengthLevelCount: int}
     */
    public static function clientConfig(): array
    {
        return [
            'minLength' => self::MIN_LENGTH,
            'maxLength' => self::MAX_LENGTH,
            'minStrengthScore' => self::MIN_STRENGTH_SCORE,
            'strengthLevelCount' => PasswordStrength::STRENGTH_VERY_STRONG + 1,
        ];
    }
}
