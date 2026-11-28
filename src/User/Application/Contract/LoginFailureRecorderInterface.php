<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

use App\User\Domain\Enum\LoginFailureKind;

/**
 * Records one failed sign-in for the daily counters (#1487). Equal identifiers (trimmed, case-insensitive) are recorded
 * alike; an empty identifier is recorded as unknown. Recording never fails the sign-in.
 */
interface LoginFailureRecorderInterface
{
    public function record(LoginFailureKind $kind, ?string $identifier, ?string $ip): void;
}
