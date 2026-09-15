<?php

declare(strict_types=1);

namespace App\User\Application\AdministrativeUser;

final class UserNotDeletableException extends \RuntimeException
{
    public const string LAST_ADMINISTRATOR = 'Cannot delete the last remaining administrator. Create or promote another administrator first.';

    public const string OWNS_HOSPITALS = 'Cannot delete this account while it still owns hospital(s). Reassign hospital ownership first.';

    public const string REFERENCED = 'This account cannot be deleted while hospitals or other records still reference it.';

    public static function lastAdministrator(): self
    {
        return new self(self::LAST_ADMINISTRATOR);
    }

    public static function ownsHospitals(): self
    {
        return new self(self::OWNS_HOSPITALS);
    }

    public static function referenced(): self
    {
        return new self(self::REFERENCED);
    }
}
