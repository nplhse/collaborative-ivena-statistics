<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

use App\User\Domain\Entity\User;

/**
 * Saves the login lock of an account after AccountLock changed it (#1762, #1487).
 */
interface AccountLockWriterInterface
{
    /** The account was locked after too many wrong passwords. */
    public function saveLock(User $user): void;

    /** An administrator lifted the lock. */
    public function saveUnlock(User $user): void;
}
