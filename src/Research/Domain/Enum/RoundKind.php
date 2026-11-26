<?php

declare(strict_types=1);

namespace App\Research\Domain\Enum;

/**
 * Why a voting round was opened (ADR 045). A round after a submitted revision is evaluated only at its deadline; the
 * others end early once every eligible member voted.
 */
enum RoundKind: string
{
    case Initial = 'initial';
    case AfterRevision = 'after_revision';
    case Restart = 'restart';

    public function endsEarlyWhenAllVoted(): bool
    {
        return self::AfterRevision !== $this;
    }
}
