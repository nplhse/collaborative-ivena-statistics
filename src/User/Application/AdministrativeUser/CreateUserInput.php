<?php

declare(strict_types=1);

namespace App\User\Application\AdministrativeUser;

final readonly class CreateUserInput
{
    public function __construct(
        public string $username,
        public string $email,
        public string $plainPassword,
        public bool $grantAdmin,
    ) {
    }
}
