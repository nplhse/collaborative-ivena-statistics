<?php

declare(strict_types=1);

namespace App\User\Domain\Security;

final class UserRole
{
    public const string USER = 'ROLE_USER';

    public const string PARTICIPANT = 'ROLE_PARTICIPANT';

    public const string BOARD_MEMBER = 'ROLE_BOARD_MEMBER';

    public const string ADMIN = 'ROLE_ADMIN';

    public const string FEEDBACK_RECIPIENT = 'ROLE_FEEDBACK_RECIPIENT';

    public const string RECEIVES_NOTIFICATION = 'ROLE_RECEIVES_NOTIFICATION';

    public const string REVIEW_INDICATIONS = 'ROLE_REVIEW_INDICATIONS';

    public static function containsParticipant(mixed $roles): bool
    {
        if (!\is_array($roles)) {
            return false;
        }

        return array_any($roles, fn ($role): bool => self::PARTICIPANT === $role);
    }
}
