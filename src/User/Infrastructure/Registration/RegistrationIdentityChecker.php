<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Registration;

use App\User\Domain\Entity\User;
use App\User\Domain\Validator\UserUsernameConstraints;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\Form\FormError;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shared uniqueness check for registration and administrative user creation (generic message — no field
 * leak). Usernames are compared case-insensitively, emails lowercased.
 *
 * @psalm-suppress UnusedClass Wired via services.yaml alias for RegistrationIdentityGuard
 */
final readonly class RegistrationIdentityChecker implements RegistrationIdentityGuard
{
    public const string MESSAGE_KEY = 'validation.registration.identity_taken';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private UserRepository $userRepository,
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function isIdentityTaken(string $username, string $email): bool
    {
        $normalizedUsername = UserUsernameConstraints::trim($username);
        $normalizedEmail = mb_strtolower(trim($email));

        // Usernames differing only in case are the same identity (#1353): profile URLs ignore the case.
        return $this->userRepository->findOneByUsernameInsensitive($normalizedUsername) instanceof User
            || null !== $this->userRepository->findOneBy(['email' => $normalizedEmail]);
    }

    #[\Override]
    public function createIdentityTakenFormError(): FormError
    {
        return new FormError($this->translator->trans(self::MESSAGE_KEY, [], 'validators'));
    }
}
