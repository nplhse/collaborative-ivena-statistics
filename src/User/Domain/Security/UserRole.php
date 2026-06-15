<?php

declare(strict_types=1);

namespace App\User\Domain\Security;

final class UserRole
{
    public const string USER = 'ROLE_USER';

    public const string PARTICIPANT = 'ROLE_PARTICIPANT';

    public const string ADMIN = 'ROLE_ADMIN';

    public const string FEEDBACK_RECIPIENT = 'ROLE_FEEDBACK_RECIPIENT';

    public const string RECEIVES_NOTIFICATION = 'ROLE_RECEIVES_NOTIFICATION';
}
