<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

use App\Allocation\Domain\Entity\Assessment;
use Doctrine\DBAL\Connection;

final readonly class ImportAssessmentAuditPurgeQuery
{
    private const string ENTITY_CLASS = Assessment::class;

    private const string ACTION_CREATE = 'create';

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function countCandidates(): int
    {
        return (int) $this->connection->fetchOne(
            $this->candidateCountSql(),
            $this->params(),
        );
    }

    /**
     * @return array{min: \DateTimeImmutable, max: \DateTimeImmutable}|null
     */
    public function fetchOccurredAtRange(): ?array
    {
        $row = $this->connection->fetchAssociative(
            <<<SQL
            SELECT MIN(al.occurred_at) AS min_at, MAX(al.occurred_at) AS max_at
            FROM audit_log al
            INNER JOIN allocation a ON a.assessment_id::text = al.entity_id
            WHERE al.entity_class = :entity_class
              AND al.action = :action
              AND a.assessment_id IS NOT NULL
            SQL,
            $this->params(),
        );

        if (false === $row || null === $row['min_at'] || null === $row['max_at']) {
            return null;
        }

        return [
            'min' => new \DateTimeImmutable((string) $row['min_at']),
            'max' => new \DateTimeImmutable((string) $row['max_at']),
        ];
    }

    public function deleteCandidates(): int
    {
        return $this->connection->executeStatement(
            <<<SQL
            DELETE FROM audit_log al
            USING allocation a
            WHERE a.assessment_id::text = al.entity_id
              AND al.entity_class = :entity_class
              AND al.action = :action
              AND a.assessment_id IS NOT NULL
            SQL,
            $this->params(),
        );
    }

    private function candidateCountSql(): string
    {
        return <<<SQL
            SELECT COUNT(*)::int
            FROM audit_log al
            INNER JOIN allocation a ON a.assessment_id::text = al.entity_id
            WHERE al.entity_class = :entity_class
              AND al.action = :action
              AND a.assessment_id IS NOT NULL
            SQL;
    }

    /**
     * @return array{entity_class: string, action: string}
     */
    private function params(): array
    {
        return [
            'entity_class' => self::ENTITY_CLASS,
            'action' => self::ACTION_CREATE,
        ];
    }
}
