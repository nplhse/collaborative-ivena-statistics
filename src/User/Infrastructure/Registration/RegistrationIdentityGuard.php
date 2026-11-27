<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Registration;

use Symfony\Component\Form\FormError;

interface RegistrationIdentityGuard
{
    /** Username or email; for accounts created by administrators, who may see both conflicts. */
    public function isIdentityTaken(string $username, string $email): bool;

    /**
     * Self-registration only checks the username (#936): usernames are public in Explore, so the conflict may show,
     * but whether an email address has an account must not.
     */
    public function isUsernameTaken(string $username): bool;

    public function createUsernameTakenFormError(): FormError;
}
