<?php

declare(strict_types=1);

namespace App\User\Domain\Enum;

/**
 * Kind of a recorded sign-in failure (#1487).
 */
enum LoginFailureKind: string
{
    /** Wrong credentials, an unknown username or another refused sign-in. */
    case Failure = 'failure';

    /** Refused before the password was checked: `login_throttling` or a locked account (#1762). */
    case Refused = 'refused';

    /** The account lock threshold was reached while the lock is not enforced (#1762). */
    case LockThresholdReached = 'lock_threshold';

    /** An account was locked (#1762). */
    case AccountLocked = 'account_locked';
}
