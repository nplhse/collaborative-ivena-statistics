<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Security\AccountLock;

use App\Tests\User\Contract\AccountLockWriterContract;
use App\User\Application\Contract\AccountLockWriterInterface;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Security\AccountLock\DoctrineAccountLockWriter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(DoctrineAccountLockWriter::class)]
#[ResetDatabase]
final class DoctrineAccountLockWriterTest extends AccountLockWriterContract
{
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testTheLockIsSavedUnderItsAuditIntent(): void
    {
        $user = $this->user();
        $user->lockLoginUntil(new \DateTimeImmutable('2026-11-27 13:00:00'));

        $this->writer()->saveLock($user);

        self::assertSame(1, (int) self::getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE intent = 'user.login.account_locked'",
        ));
    }

    public function testTheUnlockIsSavedUnderItsAuditIntent(): void
    {
        $user = $this->user();
        $user->lockLoginUntil(new \DateTimeImmutable('2026-11-27 13:00:00'));
        $this->writer()->saveLock($user);
        $user->clearLoginLock();

        $this->writer()->saveUnlock($user);

        self::assertSame(1, (int) self::getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE intent = 'user.login.account_unlocked'",
        ));
    }

    #[\Override]
    protected function writer(): AccountLockWriterInterface
    {
        return self::getContainer()->get(AccountLockWriterInterface::class);
    }

    #[\Override]
    protected function user(): User
    {
        return UserFactory::createOne(['username' => 'target', 'email' => 'target@example.test']);
    }

    #[\Override]
    protected function storedLock(User $user): ?\DateTimeImmutable
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(User::class, $user->getId())?->getLoginLockedUntil();
    }
}
