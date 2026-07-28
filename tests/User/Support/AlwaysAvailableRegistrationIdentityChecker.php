<?php

declare(strict_types=1);

namespace App\Tests\User\Support;

use App\User\Infrastructure\Registration\RegistrationIdentityChecker;
use App\User\Infrastructure\Registration\RegistrationIdentityGuard;
use Symfony\Component\Form\FormError;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Simulates a TOCTOU race: uniqueness check passes, DB unique constraint still fails on flush.
 */
final readonly class AlwaysAvailableRegistrationIdentityChecker implements RegistrationIdentityGuard
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function isIdentityTaken(string $username, string $email): bool
    {
        return false;
    }

    #[\Override]
    public function createIdentityTakenFormError(): FormError
    {
        return new FormError(
            $this->translator->trans(RegistrationIdentityChecker::MESSAGE_KEY, [], 'validators'),
        );
    }
}
