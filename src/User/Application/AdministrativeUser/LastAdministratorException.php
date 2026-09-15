<?php

declare(strict_types=1);

namespace App\User\Application\AdministrativeUser;

final class LastAdministratorException extends \RuntimeException
{
    public const string MESSAGE = 'Cannot demote the last remaining administrator. Create or promote another administrator first.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
