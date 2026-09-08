<?php

declare(strict_types=1);

namespace App\User\Domain\Validator;

use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

final class UserUsernameConstraints
{
    public const int MIN_LENGTH = 3;

    public const int MAX_LENGTH = 180;

    public const string PATTERN = '/^[\p{L}\p{N}._-]+$/u';

    /**
     * @return list<NotBlank|Length|Regex>
     */
    public static function forUsername(): array
    {
        return [
            new NotBlank(),
            new Length(min: self::MIN_LENGTH, max: self::MAX_LENGTH),
            new Regex(
                pattern: self::PATTERN,
                message: 'validation.username.format',
            ),
        ];
    }

    /**
     * Unicode-aware edge trim, matching Symfony form TrimListener / StringUtil::trim.
     */
    public static function trim(string $username): string
    {
        $trimmed = preg_replace('/^[\pZ\p{Cc}]+|[\pZ\p{Cc}]+$/u', '', $username);

        return $trimmed ?? $username;
    }

    public static function proposeNormalized(string $username): string
    {
        $trimmed = self::trim($username);
        $replaced = preg_replace('/[\s\p{Z}]+/u', '-', $trimmed);

        return $replaced ?? $trimmed;
    }

    public static function isValid(string $username): bool
    {
        $length = mb_strlen($username);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return false;
        }

        return 1 === preg_match(self::PATTERN, $username);
    }
}
