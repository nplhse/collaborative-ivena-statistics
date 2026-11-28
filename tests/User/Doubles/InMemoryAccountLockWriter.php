<?php

declare(strict_types=1);

namespace App\Tests\User\Doubles;

use App\User\Application\Contract\AccountLockWriterInterface;
use App\User\Domain\Entity\User;

/**
 * Keeps the saved login lock per user id. AccountLockWriterContract proves that it behaves like
 * DoctrineAccountLockWriter.
 */
final class InMemoryAccountLockWriter implements AccountLockWriterInterface
{
    /** @var array<int, ?\DateTimeImmutable> */
    public array $savedLocks = [];

    public int $saves = 0;

    public int $unlocks = 0;

    #[\Override]
    public function saveLock(User $user): void
    {
        $this->savedLocks[(int) $user->getId()] = $user->getLoginLockedUntil();
        ++$this->saves;
    }

    #[\Override]
    public function saveUnlock(User $user): void
    {
        $this->savedLocks[(int) $user->getId()] = $user->getLoginLockedUntil();
        ++$this->unlocks;
    }
}
