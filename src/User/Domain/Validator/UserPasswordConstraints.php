<?php

declare(strict_types=1);

namespace App\User\Domain\Validator;

use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\PasswordStrength;

final class UserPasswordConstraints
{
    /**
     * Moderate password rules: min length, basic strength, breached-password check.
     *
     * @return list<NotBlank|Length|PasswordStrength|NotCompromisedPassword>
     */
    public static function forPlainPassword(): array
    {
        return [
            new NotBlank(),
            new Length(min: 8, max: 4096),
            new PasswordStrength(minScore: PasswordStrength::STRENGTH_WEAK),
            new NotCompromisedPassword(),
        ];
    }
}
