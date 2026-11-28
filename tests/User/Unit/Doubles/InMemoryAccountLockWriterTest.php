<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Doubles;

use App\Tests\User\Contract\AccountLockWriterContract;
use App\Tests\User\Doubles\InMemoryAccountLockWriter;
use App\User\Application\Contract\AccountLockWriterInterface;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryAccountLockWriterTest extends AccountLockWriterContract
{
    private InMemoryAccountLockWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new InMemoryAccountLockWriter();
    }

    #[\Override]
    protected function writer(): AccountLockWriterInterface
    {
        return $this->writer;
    }

    #[\Override]
    protected function user(): User
    {
        $user = new User()->setUsername('user-7');
        new \ReflectionProperty(User::class, 'id')->setValue($user, 7);

        return $user;
    }

    #[\Override]
    protected function storedLock(User $user): ?\DateTimeImmutable
    {
        return $this->writer->savedLocks[(int) $user->getId()] ?? null;
    }
}
