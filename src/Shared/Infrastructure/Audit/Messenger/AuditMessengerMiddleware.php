<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit\Messenger;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;

/**
 * @psalm-suppress UnusedClass
 */
final readonly class AuditMessengerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuditContext $auditContext,
        private TokenStorageInterface $tokenStorage,
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $received = $envelope->last(ReceivedStamp::class) instanceof \Symfony\Component\Messenger\Stamp\StampInterface;

        if (!$received) {
            $envelope = $this->stampFromContext($envelope);
        } else {
            $this->hydrateContextFromEnvelope($envelope);
            $this->authenticateActorIfPossible($envelope);
        }

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            if ($received) {
                $this->tokenStorage->setToken(null);
                $this->auditContext->reset();
            }
        }
    }

    private function stampFromContext(Envelope $envelope): Envelope
    {
        if (!$envelope->last(RequestIdStamp::class) instanceof RequestIdStamp) {
            $id = $this->auditContext->ensureRequestId(static fn (): string => Uuid::v7()->toRfc4122());
            $envelope = $envelope->with(new RequestIdStamp($id));
        }

        $actorId = $this->auditContext->getActorUserId();
        if (null === $actorId) {
            $token = $this->tokenStorage->getToken();
            $actorId = AuditContext::userIdFromInterface($token?->getUser());
        }

        if (!$envelope->last(ActorStamp::class) instanceof ActorStamp && null !== $actorId) {
            $envelope = $envelope->with(new ActorStamp($actorId));
        }

        return $envelope;
    }

    private function hydrateContextFromEnvelope(Envelope $envelope): void
    {
        $this->auditContext->setOrigin(AuditContext::ORIGIN_MESSENGER);

        $rid = $envelope->last(RequestIdStamp::class);
        if ($rid instanceof RequestIdStamp) {
            $this->auditContext->setRequestId($rid->getRequestId());
        } else {
            $this->auditContext->ensureRequestId(static fn (): string => Uuid::v7()->toRfc4122());
        }

        $actor = $envelope->last(ActorStamp::class);
        if ($actor instanceof ActorStamp) {
            $this->auditContext->setActorUserId($actor->getUserId());
        }
    }

    private function authenticateActorIfPossible(Envelope $envelope): void
    {
        $actor = $envelope->last(ActorStamp::class);
        if (!$actor instanceof ActorStamp || null === $actor->getUserId()) {
            return;
        }

        $user = $this->em->find(User::class, $actor->getUserId());
        if (!$user instanceof User) {
            return;
        }

        $this->tokenStorage->setToken(new PostAuthenticationToken($user, 'main', $user->getRoles()));
    }
}
