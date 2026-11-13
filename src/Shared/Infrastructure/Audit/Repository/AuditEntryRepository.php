<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit\Repository;

use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditEntry>
 */
final class AuditEntryRepository extends ServiceEntityRepository
{
    /**
     * @psalm-suppress PossiblyUnusedMethod
     * @psalm-suppress UnusedParam
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditEntry::class);
    }

    /**
     * Newest audit events with one of the given intents, one row per event: entries of the same request and intent
     * (one flush can write several) are reduced to the first one (#1089). Each row carries what the admin dashboard
     * needs to describe the event and link to its request in the audit timeline (#1137).
     *
     * @param list<string> $intents
     *
     * @return list<array{id: int, occurredAt: \DateTimeImmutable, requestId: string, intent: string, entityClass: string, entityId: ?string, intentMetadata: array<string, mixed>}>
     */
    public function findRecentByIntents(array $intents, int $limit = 10): array
    {
        if ([] === $intents || $limit < 1) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT id, occurred_at, request_id, intent, entity_class, entity_id, metadata
                FROM (
                    SELECT DISTINCT ON (request_id, intent) id, occurred_at, request_id, intent, entity_class, entity_id, metadata
                    FROM audit_log
                    WHERE intent IN (:intents)
                    ORDER BY request_id, intent, occurred_at ASC, id ASC
                ) AS events
                ORDER BY occurred_at DESC, id DESC
                LIMIT :limit
                SQL,
            ['intents' => $intents, 'limit' => $limit],
            ['intents' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'occurredAt' => new \DateTimeImmutable((string) $row['occurred_at']),
                'requestId' => (string) $row['request_id'],
                'intent' => (string) $row['intent'],
                'entityClass' => (string) $row['entity_class'],
                'entityId' => null === $row['entity_id'] ? null : (string) $row['entity_id'],
                'intentMetadata' => self::intentMetadata($row['metadata']),
            ],
            $rows,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function intentMetadata(mixed $metadata): array
    {
        if (!\is_string($metadata) || '' === $metadata) {
            return [];
        }

        $decoded = json_decode($metadata, true);
        $intentMetadata = \is_array($decoded) ? ($decoded['intent_metadata'] ?? null) : null;

        /* @var array<string, mixed> */
        return \is_array($intentMetadata) ? $intentMetadata : [];
    }

    /**
     * @return list<AuditEntry>
     */
    public function findRecentForEntity(string $entityClass, string $entityId, int $limit = 10): array
    {
        /** @var list<AuditEntry> $entries */
        $entries = $this->createQueryBuilder('a')
            ->andWhere('a.entityClass = :entityClass')
            ->andWhere('a.entityId = :entityId')
            ->setParameter('entityClass', $entityClass)
            ->setParameter('entityId', $entityId)
            ->orderBy('a.occurredAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $entries;
    }
}
