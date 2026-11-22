<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\TwoFactor;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Switches off two-factor authentication of an account that lost its authenticator app and its backup codes (#1357,
 * ADR 040): by an administrator in the user list or by an operator on the console. Writes an audit entry and tells the
 * user by mail (`security.two_factor_reset`, email mandatory).
 */
final readonly class TwoFactorReset
{
    public const string INTENT_ADMIN = 'user.two_factor.reset_by_admin';

    public const string INTENT_CONSOLE = 'user.two_factor.reset_by_console';

    /** @psalm-suppress PossiblyUnusedMethod Built by the container. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditContext $auditContext,
        private NotificationPublisherInterface $notificationPublisher,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param self::INTENT_* $intent
     *
     * @return bool false when the account had no two-factor authentication
     */
    public function reset(User $user, string $intent): bool
    {
        if (!$user->isTotpAuthenticationEnabled()) {
            return false;
        }

        $user->disableTotpAuthentication();
        $this->auditContext->beginIntent($intent, []);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }

        $this->notificationPublisher->publish(new NotificationRequest(
            type: 'security.two_factor_reset',
            recipientUserIds: [(int) $user->getId()],
            deduplicationKey: \sprintf('security.two_factor_reset:%d:%s', (int) $user->getId(), $this->clock->now()->format('YmdHis')),
            action: new NotificationAction('app_settings_two_factor'),
        ));

        return true;
    }
}
