<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\AccountLock;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Application\Contract\AccountLockWriterInterface;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Flushes the locked account under the audit intent INTENT_LOCKED.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for AccountLockWriterInterface.
 */
#[AsAlias(AccountLockWriterInterface::class)]
final readonly class DoctrineAccountLockWriter implements AccountLockWriterInterface
{
    public const string INTENT_LOCKED = 'user.login.account_locked';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditContext $auditContext,
    ) {
    }

    /** @psalm-suppress UnusedParam The user is managed; flushing writes its lock. */
    #[\Override]
    public function saveLock(User $user): void
    {
        $this->auditContext->beginIntent(self::INTENT_LOCKED, []);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }
    }
}
