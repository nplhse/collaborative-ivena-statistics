<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit\Repository;

use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * @param list<string> $intents
     *
     * @return list<array{occurredAt: \DateTimeImmutable, intent: string, action: string, origin: string}>
     */
    public function findRecentByIntents(array $intents, int $limit = 10): array
    {
        if ([] === $intents) {
            return [];
        }

        /** @var list<AuditEntry> $entries */
        $entries = $this->createQueryBuilder('a')
            ->orderBy('a.occurredAt', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        $results = [];
        foreach ($entries as $entry) {
            $metadata = $entry->getMetadata();
            if (!\is_array($metadata) || !isset($metadata['intent']) || !\is_string($metadata['intent'])) {
                continue;
            }

            if (!\in_array($metadata['intent'], $intents, true)) {
                continue;
            }

            $results[] = [
                'occurredAt' => $entry->getOccurredAt(),
                'intent' => $metadata['intent'],
                'action' => $entry->getAction(),
                'origin' => $entry->getOrigin(),
            ];

            if (\count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }
}
