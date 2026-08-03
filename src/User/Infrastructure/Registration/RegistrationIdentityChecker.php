<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Registration;

use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\Form\FormError;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shared uniqueness check for registration (generic message — no field leak).
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
        $normalizedEmail = mb_strtolower(trim($email));

        return null !== $this->userRepository->findOneBy(['username' => $username])
            || null !== $this->userRepository->findOneBy(['email' => $normalizedEmail]);
    }

    #[\Override]
    public function createIdentityTakenFormError(): FormError
    {
        return new FormError($this->translator->trans(self::MESSAGE_KEY, [], 'validators'));
    }
}
