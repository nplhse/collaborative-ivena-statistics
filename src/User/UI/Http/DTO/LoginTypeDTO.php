<?php

namespace App\User\UI\Http\DTO;

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
        return $this->username;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
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
