<?php

declare(strict_types=1);

namespace App\User\UI\Http\DTO;

use App\User\Domain\Validator\UserUsernameConstraints;

final class LoginTypeDTO
{
    public function __construct(
        public string $username = '',
        public string $password = '',
        public bool $_remember_me = false,
    ) {
    }

    public function getUsername(): string
    {
        return UserUsernameConstraints::trim($this->username);
    }

    public function setUsername(string $username): void
    {
        $this->username = UserUsernameConstraints::trim($username);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getRememberMe(): bool
    {
        return $this->_remember_me;
    }
}
