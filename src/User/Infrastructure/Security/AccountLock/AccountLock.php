<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\AccountLock;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\User\Application\Contract\AccountLockWriterInterface;
use App\User\Application\Contract\LoginFailureRecorderInterface;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\LoginFailureKind;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Locks password and login link sign-ins of an account after too many wrong passwords from any IP (#1762).
 *
 * `login_throttling` only counts per IP and username, so many IPs can keep guessing one account. Every wrong password
 * for an existing account takes a token of `login_account_failure`; the one that uses up the last token (the 20th within
 * an hour) locks the account for LOCK_DURATION. The lock ends on its own, with a password reset by the owner or when an administrator lifts it. The
 * owner is told once per lock (`security.account_locked`, email mandatory, also in the app).
 *
 * The lock is enforced only with `app.security.account_lock.enabled`. Off (the default for now), the threshold is still
 * counted and logged as `security.login.account_lock_threshold_reached`, so the frequency of such attacks can be
 * seen before sign-ins are refused. Both are recorded for the daily counters (#1487).
 */
final readonly class AccountLock
{
    public const string LOCK_DURATION = '+3 hours';

    public const int LOCK_DURATION_HOURS = 3;

    /** @psalm-suppress PossiblyUnusedMethod Built by the container. */
    public function __construct(
        #[Autowire(service: 'limiter.login_account_failure')]
        private RateLimiterFactoryInterface $failureLimiter,
        private AccountLockWriterInterface $writer,
        private NotificationPublisherInterface $notificationPublisher,
        private ClockInterface $clock,
        #[Autowire(service: 'monolog.logger.security')]
        private LoggerInterface $logger,
        private LoginFailureRecorderInterface $recorder,
        #[Autowire(param: 'app.security.account_lock.enabled')]
        private bool $enforced,
    ) {
    }

    /** Always false while the lock is not enforced, also for accounts locked before it was switched off. */
    public function isLocked(User $user): bool
    {
        return $this->enforced && $user->isLoginLockedAt($this->clock->now());
    }

    /** Counts a wrong password; locks the account when the limit is exceeded. Does nothing while it is locked. */
    public function recordFailedPassword(User $user): void
    {
        $id = $user->getId();
        if (null === $id || $this->isLocked($user)) {
            return;
        }

        $limit = $this->failureLimiter->create($this->limiterKey($id))->consume();
        if ($limit->isAccepted() && $limit->getRemainingTokens() > 0) {
            return;
        }

        if (!$this->enforced) {
            // Off (default): count how often the threshold is reached, without locking or telling the owner.
            $this->failureLimiter->create($this->limiterKey($id))->reset();
            $this->logger->warning('security.login.account_lock_threshold_reached', ['user_id' => $id]);
            $this->recorder->record(LoginFailureKind::LockThresholdReached, $user->getUserIdentifier(), null);

            return;
        }

        $now = $this->clock->now();
        $user->lockLoginUntil($now->modify(self::LOCK_DURATION));
        $this->writer->saveLock($user);
        $this->failureLimiter->create($this->limiterKey($id))->reset();

        $this->logger->warning('security.login.account_locked', ['user_id' => $id]);
        $this->recorder->record(LoginFailureKind::AccountLocked, $user->getUserIdentifier(), null);

        $this->notificationPublisher->publish(new NotificationRequest(
            type: 'security.account_locked',
            recipientUserIds: [$id],
            deduplicationKey: \sprintf('security.account_locked:%d:%s', $id, $now->format('YmdHis')),
            parameters: ['hours' => self::LOCK_DURATION_HOURS],
            action: new NotificationAction('app_forgot_password_request'),
        ));
    }

    /** An administrator lifts the lock (#1487): forgets earlier wrong passwords and saves the account. */
    public function unlock(User $user): void
    {
        $this->clear($user);
        $this->writer->saveUnlock($user);
        $this->logger->info('security.login.account_unlocked', ['user_id' => $user->getId()]);
    }

    /**
     * Lifts a lock and forgets earlier wrong passwords. The caller flushes, under its own audit intent; returns whether
     * the account was locked.
     */
    public function clear(User $user): bool
    {
        $id = $user->getId();
        if (null !== $id) {
            $this->failureLimiter->create($this->limiterKey($id))->reset();
        }

        $wasLocked = $this->isLocked($user);
        if ($user->getLoginLockedUntil() instanceof \DateTimeImmutable) {
            $user->clearLoginLock();
        }

        return $wasLocked;
    }

    private function limiterKey(int $userId): string
    {
        return 'user-'.$userId;
    }
}
