<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\Terms;

use App\User\Application\Terms\TermsVersion;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\TermsAcceptanceRepository;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Whether an account still has to accept the configured version of the terms of use (#1564, ADR 043). The session
 * remembers account and accepted version, so the database is asked once per session and again when the version or the
 * account changes.
 */
final readonly class TermsAcceptanceStatus
{
    private const string SESSION_KEY = '_app_terms_accepted';

    /** @psalm-suppress PossiblyUnusedMethod Built by the container. */
    public function __construct(
        private TermsVersion $termsVersion,
        private TermsAcceptanceRepository $acceptances,
    ) {
    }

    /** Whether a version is configured at all; cheap, no user needed. */
    public function isRequired(): bool
    {
        return null !== $this->termsVersion->current();
    }

    public function isOutstanding(User $user, ?SessionInterface $session): bool
    {
        $version = $this->termsVersion->current();
        if (null === $version || $this->marker($user, $version) === $session?->get(self::SESSION_KEY)) {
            return false;
        }

        if (!$this->acceptances->hasAccepted($user, $version)) {
            return true;
        }

        $session?->set(self::SESSION_KEY, $this->marker($user, $version));

        return false;
    }

    /** Remembers in the session that this account accepted the current version. */
    public function remember(User $user, SessionInterface $session): void
    {
        $version = $this->termsVersion->current();
        if (null !== $version) {
            $session->set(self::SESSION_KEY, $this->marker($user, $version));
        }
    }

    private function marker(User $user, string $version): string
    {
        return $user->getUserIdentifier()."\n".$version;
    }
}
