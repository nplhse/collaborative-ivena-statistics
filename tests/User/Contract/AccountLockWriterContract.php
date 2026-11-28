<?php

declare(strict_types=1);

namespace App\Tests\User\Contract;

use App\User\Application\Contract\AccountLockWriterInterface;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Expectations every AccountLockWriterInterface meets; see LoginFailureRecorderContract.
 */
abstract class AccountLockWriterContract extends KernelTestCase
{
    public function testASavedLockIsKept(): void
    {
        $user = $this->user();
        $user->lockLoginUntil(new \DateTimeImmutable('2026-11-27 13:00:00'));

        $this->writer()->saveLock($user);

        self::assertEquals(new \DateTimeImmutable('2026-11-27 13:00:00'), $this->storedLock($user));
    }

    public function testASavedClearedLockIsGone(): void
    {
        $user = $this->user();
        $user->lockLoginUntil(new \DateTimeImmutable('2026-11-27 13:00:00'));
        $this->writer()->saveLock($user);
        $user->clearLoginLock();

        $this->writer()->saveLock($user);

        self::assertNull($this->storedLock($user));
    }

    abstract protected function writer(): AccountLockWriterInterface;

    /** A user the writer can save, with an id. */
    abstract protected function user(): User;

    abstract protected function storedLock(User $user): ?\DateTimeImmutable;
}
