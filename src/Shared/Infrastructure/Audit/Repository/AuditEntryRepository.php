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
     * (one flush can write several) are reduced to the first one (#1089).
     *
     * @param list<string> $intents
     *
     * @return list<array{occurredAt: \DateTimeImmutable, intent: string, action: string, origin: string}>
     */
    public function findRecentByIntents(array $intents, int $limit = 10): array
    {
        if ([] === $intents || $limit < 1) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT occurred_at, intent, action, origin
                FROM (
                    SELECT DISTINCT ON (request_id, intent) id, occurred_at, intent, action, origin
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
                'occurredAt' => new \DateTimeImmutable((string) $row['occurred_at']),
                'intent' => (string) $row['intent'],
                'action' => (string) $row['action'],
                'origin' => (string) $row['origin'],
            ],
            $rows,
        );
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
