<?php

declare(strict_types=1);

namespace App\User\Application\AdministrativeUser;

final class IdentityTakenException extends \RuntimeException
{
    public const string MESSAGE = 'This username or email address is already taken.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
