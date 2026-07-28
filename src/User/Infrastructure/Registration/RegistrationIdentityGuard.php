<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Registration;

use Symfony\Component\Form\FormError;

interface RegistrationIdentityGuard
{
    public function isIdentityTaken(string $username, string $email): bool;

    public function createIdentityTakenFormError(): FormError;
}
