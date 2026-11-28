<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

use App\User\Domain\Entity\User;

/**
 * Saves the login lock of an account after AccountLock changed it (#1762).
 */
interface AccountLockWriterInterface
{
    public function saveLock(User $user): void;
}
