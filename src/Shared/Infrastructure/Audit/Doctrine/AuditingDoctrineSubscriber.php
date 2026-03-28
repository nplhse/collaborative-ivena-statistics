<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit\Doctrine;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Audit\AuditFieldRules;
use App\Shared\Infrastructure\Audit\AuditValueNormalizer;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * @psalm-suppress UnusedClass
 */
#[AsDoctrineListener(event: Events::onFlush, priority: -100, connection: 'default')]
#[AsDoctrineListener(event: Events::postFlush, priority: -100, connection: 'default')]
final class AuditingDoctrineSubscriber
{
    private const string SENSITIVE_PLACEHOLDER = '********';

    /** @var list<array{0: AuditEntry, 1: object}> */
    private array $idBackfill = [];

    public function __construct(
        private readonly AuditContext $auditContext,
        private readonly AuditFieldRules $fieldRules,
        private readonly AuditValueNormalizer $normalizer,
        private readonly Security $security,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        if (!$em instanceof EntityManagerInterface) {
            return;
        }

        $uow = $em->getUnitOfWork();

        $insertions = [];
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$this->fieldRules->isClassAudited($entity::class)) {
                continue;
            }
            if ($this->auditContext->isEntityAuditSuppressed($entity::class)) {
                continue;
            }
            $insertions[] = $entity;
        }

        $updates = [];
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$this->fieldRules->isClassAudited($entity::class)) {
                continue;
            }
            if ($this->auditContext->isEntityAuditSuppressed($entity::class)) {
                continue;
            }
            $updates[] = $entity;
        }

        $deletions = [];
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if (!$this->fieldRules->isClassAudited($entity::class)) {
                continue;
            }
            if ($this->auditContext->isEntityAuditSuppressed($entity::class)) {
                continue;
            }
            $deletions[] = $entity;
        }

        if ([] === $insertions && [] === $updates && [] === $deletions) {
            return;
        }

        $requestId = $this->auditContext->ensureRequestId(static fn (): string => Uuid::v7()->toRfc4122());
        $origin = $this->auditContext->getOrigin() ?? AuditContext::ORIGIN_CLI;
        $actorId = $this->resolveActorUserId();
        $actor = null !== $actorId ? $em->getReference(User::class, $actorId) : null;
        $baseMetadata = $this->buildBaseMetadata();

        foreach ($insertions as $entity) {
            $snapshot = $this->buildInsertSnapshot($em, $entity);
            if ([] === $snapshot) {
                continue;
            }
            $audit = new AuditEntry(
                new \DateTimeImmutable('now'),
                $requestId,
                $actor,
                $origin,
                'create',
                $entity::class,
                $this->entityIdString($entity),
                $snapshot,
                $baseMetadata,
            );
            if (null === $this->entityIdString($entity)) {
                $this->idBackfill[] = [$audit, $entity];
            }
            $em->persist($audit);
        }

        foreach ($updates as $entity) {
            $filtered = $this->filterChangeSet($entity::class, $uow->getEntityChangeSet($entity));
            if ([] === $filtered) {
                continue;
            }
            $em->persist(new AuditEntry(
                new \DateTimeImmutable('now'),
                $requestId,
                $actor,
                $origin,
                'update',
                $entity::class,
                $this->entityIdString($entity),
                $filtered,
                $baseMetadata,
            ));
        }

        foreach ($deletions as $entity) {
            $em->persist(new AuditEntry(
                new \DateTimeImmutable('now'),
                $requestId,
                $actor,
                $origin,
                'delete',
                $entity::class,
                $this->entityIdString($entity),
                [],
                $baseMetadata,
            ));
        }

        $uow->computeChangeSets();
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $pending = $this->idBackfill;
        $this->idBackfill = [];

        if ([] === $pending) {
            return;
        }

        $em = $args->getObjectManager();
        if (!$em instanceof EntityManagerInterface) {
            return;
        }

        foreach ($pending as [$audit, $entity]) {
            $id = $this->entityIdString($entity);
            if (null !== $id) {
                $audit->setEntityId($id);
            }
        }

        $em->flush();
    }

    /** @return array<string, mixed>|null */
    private function buildBaseMetadata(): ?array
    {
        $metadata = [];

        $intent = $this->auditContext->getCurrentIntent();
        if (null !== $intent) {
            $metadata['intent'] = $intent['name'];
            if ([] !== $intent['metadata']) {
                $metadata['intent_metadata'] = $intent['metadata'];
            }
        }

        $ip = $this->auditContext->getClientIp();
        if (null !== $ip && '' !== $ip) {
            $metadata['clientIp'] = $ip;
        }

        $ua = $this->auditContext->getUserAgent();
        if (null !== $ua && '' !== $ua) {
            $metadata['userAgent'] = $ua;
        }

        return [] === $metadata ? null : $metadata;
    }

    private function resolveActorUserId(): ?int
    {
        if (null !== $this->auditContext->getActorUserId()) {
            return $this->auditContext->getActorUserId();
        }

        return AuditContext::userIdFromInterface($this->security->getUser());
    }

    private function entityIdString(object $entity): ?string
    {
        if (!method_exists($entity, 'getId')) {
            return null;
        }

        $id = $entity->getId();

        return null === $id ? null : (string) $id;
    }

    /**
     * @psalm-assert class-string $class
     */
    private function assertClassString(string $class): void
    {
        if (!class_exists($class) && !enum_exists($class)) {
            throw new \LogicException(sprintf('Invalid entity FQCN: %s', $class));
        }
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function buildInsertSnapshot(EntityManagerInterface $em, object $entity): array
    {
        $meta = $em->getClassMetadata($entity::class);
        $class = $entity::class;
        $out = [];

        foreach ($this->fieldRules->fieldAndToOneAssociationNames($meta) as $field) {
            if ($this->fieldRules->shouldSkipField($class, $field)) {
                continue;
            }

            try {
                $value = $meta->getFieldValue($entity, $field);
            } catch (\Throwable) {
                continue;
            }

            $out[$field] = [
                'old' => null,
                'new' => $this->fieldRules->isSensitiveField($class, $field)
                    ? self::SENSITIVE_PLACEHOLDER
                    : $this->normalizer->normalize($value),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $changeSet
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function filterChangeSet(string $entityClass, array $changeSet): array
    {
        $this->assertClassString($entityClass);

        $out = [];

        foreach ($changeSet as $field => $tuple) {
            if ($this->fieldRules->shouldSkipField($entityClass, $field)) {
                continue;
            }

            if (!\is_array($tuple)) {
                continue;
            }

            if (!\array_key_exists(0, $tuple) || !\array_key_exists(1, $tuple)) {
                continue;
            }

            $old = $tuple[0];
            $new = $tuple[1];
            if ($old == $new) {
                continue;
            }

            if ($this->fieldRules->isSensitiveField($entityClass, $field)) {
                $out[$field] = [
                    'old' => self::SENSITIVE_PLACEHOLDER,
                    'new' => self::SENSITIVE_PLACEHOLDER,
                ];

                continue;
            }

            $out[$field] = [
                'old' => $this->normalizer->normalize($old),
                'new' => $this->normalizer->normalize($new),
            ];
        }

        return $out;
    }
}
