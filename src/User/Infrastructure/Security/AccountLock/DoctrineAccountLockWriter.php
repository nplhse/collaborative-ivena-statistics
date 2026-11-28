<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\AccountLock;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Application\Contract\AccountLockWriterInterface;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Flushes the account under the audit intent INTENT_LOCKED or INTENT_UNLOCKED.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for AccountLockWriterInterface.
 */
#[AsAlias(AccountLockWriterInterface::class)]
final readonly class DoctrineAccountLockWriter implements AccountLockWriterInterface
{
    public const string INTENT_LOCKED = 'user.login.account_locked';

    public const string INTENT_UNLOCKED = 'user.login.account_unlocked';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditContext $auditContext,
    ) {
    }

    /** @psalm-suppress UnusedParam The user is managed; flushing writes its lock. */
    #[\Override]
    public function saveLock(User $user): void
    {
        $this->flush(self::INTENT_LOCKED);
    }

    /** @psalm-suppress UnusedParam The user is managed; flushing writes its lock. */
    #[\Override]
    public function saveUnlock(User $user): void
    {
        $this->flush(self::INTENT_UNLOCKED);
    }

    private function flush(string $intent): void
    {
        $this->auditContext->beginIntent($intent, []);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }
    }
}
