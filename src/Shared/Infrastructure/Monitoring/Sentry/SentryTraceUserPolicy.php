<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Sentry;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether the performance transaction of the current request may carry the user id.
 *
 * Error reports always carry it (the operator's interest in running the platform); transactions only with the
 * performance consent. SentryRequestContextSubscriber decides per request, the before_send_transaction callback
 * removes the user otherwise. Reset between requests and messages.
 */
final class SentryTraceUserPolicy implements ResetInterface
{
    private bool $userAllowed = false;

    public function allowUser(bool $allowed): void
    {
        $this->userAllowed = $allowed;
    }

    public function isUserAllowed(): bool
    {
        return $this->userAllowed;
    }

    #[\Override]
    public function reset(): void
    {
        $this->userAllowed = false;
    }
}
