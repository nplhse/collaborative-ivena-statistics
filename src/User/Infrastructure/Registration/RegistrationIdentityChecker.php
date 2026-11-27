<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Registration;

use App\User\Domain\Entity\User;
use App\User\Domain\Validator\UserUsernameConstraints;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\Form\FormError;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Uniqueness checks for registration and administrative user creation. Usernames are compared case-insensitively,
 * emails lowercased. Self-registration only reports a taken username (#936); a taken email is answered like a new one
 * by RegistrationController.
 *
 * @psalm-suppress UnusedClass Wired via services.yaml alias for RegistrationIdentityGuard
 */
final readonly class RegistrationIdentityChecker implements RegistrationIdentityGuard
{
    public const string USERNAME_TAKEN_MESSAGE_KEY = 'validation.registration.username_taken';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private UserRepository $userRepository,
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function isIdentityTaken(string $username, string $email): bool
    {
        $normalizedEmail = mb_strtolower(trim($email));

        return $this->isUsernameTaken($username)
            || null !== $this->userRepository->findOneBy(['email' => $normalizedEmail]);
    }

    #[\Override]
    public function isUsernameTaken(string $username): bool
    {
        // Usernames differing only in case are the same identity (#1353): profile URLs ignore the case.
        return $this->userRepository->findOneByUsernameInsensitive(UserUsernameConstraints::trim($username)) instanceof User;
    }

    #[\Override]
    public function createUsernameTakenFormError(): FormError
    {
        return new FormError($this->translator->trans(self::USERNAME_TAKEN_MESSAGE_KEY, [], 'validators'));
    }
}
