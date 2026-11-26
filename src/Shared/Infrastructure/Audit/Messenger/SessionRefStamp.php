<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the audit session reference of the dispatching session to the handler (ADR 025).
 */
final readonly class SessionRefStamp implements StampInterface
{
    public function __construct(
        private string $sessionRef,
    ) {
    }

    public function getSessionRef(): string
    {
        return $this->sessionRef;
    }
}
