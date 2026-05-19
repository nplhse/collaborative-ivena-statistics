<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Http\SecurityEvents;
use Symfony\Component\Uid\Uuid;

/**
 * @psalm-suppress UnusedClass
 */
final readonly class SwitchUserSubscriber
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditContext $auditContext,
        private Security $security,
    ) {
    }

    #[AsEventListener(event: SecurityEvents::SWITCH_USER)]
    public function onSwitchUser(SwitchUserEvent $event): void
    {
        $token = $event->getToken();
        if (!$token instanceof \Symfony\Component\Security\Core\Authentication\Token\TokenInterface) {
            return;
        }

        if ($token instanceof SwitchUserToken) {
            $this->assertCanImpersonate($token, $event->getTargetUser());
            $this->recordImpersonationAudit('impersonate', $token, $event->getTargetUser());

            return;
        }

        $impersonatedUser = $this->security->getUser();
        $this->recordImpersonationAudit('impersonate_exit', $token, $event->getTargetUser(), $impersonatedUser);
    }

    private function assertCanImpersonate(SwitchUserToken $token, object $targetUser): void
    {
        if (!$targetUser instanceof User) {
            throw new AccessDeniedException('Switch user failed.');
        }

        $impersonator = $token->getOriginalToken()->getUser();
        if (!$impersonator instanceof User) {
            throw new AccessDeniedException('Switch user failed.');
        }

        if ($targetUser->getUserIdentifier() === $impersonator->getUserIdentifier()) {
            throw new AccessDeniedException('You cannot impersonate yourself.');
        }

        if (!$targetUser->isEnabled()) {
            throw new AccessDeniedException('Cannot impersonate a disabled account.');
        }

        if (\in_array('ROLE_ADMIN', $targetUser->getRoles(), true)) {
            throw new AccessDeniedException('Cannot impersonate administrators.');
        }
    }

    private function recordImpersonationAudit(
        string $action,
        object $token,
        object $targetUser,
        ?object $impersonatedUser = null,
    ): void {
        $actor = $this->resolveActor($token, $targetUser);
        if (!$actor instanceof User) {
            return;
        }

        $subject = $this->resolveSubject($action, $targetUser, $impersonatedUser);
        if (!$subject instanceof User) {
            return;
        }

        $requestId = $this->auditContext->ensureRequestId(static fn (): string => Uuid::v7()->toRfc4122());
        $origin = $this->auditContext->getOrigin() ?? AuditContext::ORIGIN_HTTP;

        $metadata = [
            'impersonatorUsername' => $actor->getUserIdentifier(),
            'targetUsername' => $subject->getUserIdentifier(),
        ];

        $subjectId = $subject->getId();
        if (null !== $subjectId) {
            $metadata['targetUserId'] = $subjectId;
        }

        $actorId = $actor->getId();
        if (null !== $actorId) {
            $metadata['impersonatorUserId'] = $actorId;
        }

        $clientIp = $this->auditContext->getClientIp();
        if (null !== $clientIp && '' !== $clientIp) {
            $metadata['clientIp'] = $clientIp;
        }

        $userAgent = $this->auditContext->getUserAgent();
        if (null !== $userAgent && '' !== $userAgent) {
            $metadata['userAgent'] = $userAgent;
        }

        $this->entityManager->persist(new AuditEntry(
            new \DateTimeImmutable('now'),
            $requestId,
            $actor,
            $origin,
            $action,
            User::class,
            null !== $subjectId ? (string) $subjectId : null,
            [],
            $metadata,
        ));
        $this->entityManager->flush();
    }

    private function resolveActor(object $token, object $targetUser): ?User
    {
        if ($token instanceof SwitchUserToken) {
            $user = $token->getOriginalToken()->getUser();

            return $user instanceof User ? $user : null;
        }

        return $targetUser instanceof User ? $targetUser : null;
    }

    private function resolveSubject(string $action, object $targetUser, ?object $impersonatedUser): ?User
    {
        if ('impersonate_exit' === $action) {
            return $impersonatedUser instanceof User ? $impersonatedUser : null;
        }

        return $targetUser instanceof User ? $targetUser : null;
    }
}
