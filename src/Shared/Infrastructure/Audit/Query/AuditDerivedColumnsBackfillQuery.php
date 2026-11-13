<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit\Query;

use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use Doctrine\DBAL\Connection;

/**
 * Copies intent and outcome of audit entries written before ADR 025 from their stored metadata.
 *
 * The rules match AuditEntry::intentFrom() and AuditEntry::outcomeFrom(). Only rows whose columns are
 * still empty are touched, so the backfill can run again and changes nothing the second time.
 */
final readonly class AuditDerivedColumnsBackfillQuery
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function countMissingIntent(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE intent IS NULL AND '.$this->intentSql().' IS NOT NULL',
        );
    }

    public function countMissingOutcome(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE outcome IS NULL AND '.$this->outcomeSql().' IS NOT NULL',
            $this->outcomeParams(),
        );
    }

    public function fillIntent(): int
    {
        return $this->connection->executeStatement(
            'UPDATE audit_log SET intent = '.$this->intentSql().' WHERE intent IS NULL AND '.$this->intentSql().' IS NOT NULL',
        );
    }

    public function fillOutcome(): int
    {
        return $this->connection->executeStatement(
            'UPDATE audit_log SET outcome = '.$this->outcomeSql().' WHERE outcome IS NULL AND '.$this->outcomeSql().' IS NOT NULL',
            $this->outcomeParams(),
        );
    }

    /**
     * metadata.intent when it is a non-empty string.
     */
    private function intentSql(): string
    {
        return "(CASE WHEN json_typeof(metadata->'intent') = 'string' THEN NULLIF(metadata->>'intent', '') END)";
    }

    /**
     * Exports: blocked = true → blocked, else success true/false → success/failed. import.run.failed → failed.
     */
    private function outcomeSql(): string
    {
        return <<<'SQL'
            (CASE
                WHEN action = :export AND json_typeof(metadata->'blocked') = 'boolean' AND (metadata->>'blocked')::boolean THEN :blocked
                WHEN action = :export AND json_typeof(metadata->'success') = 'boolean' AND (metadata->>'success')::boolean THEN :success
                WHEN action = :export AND json_typeof(metadata->'success') = 'boolean' THEN :failed
                WHEN action <> :export AND json_typeof(metadata->'intent') = 'string' AND metadata->>'intent' = :importRunFailed THEN :failed
            END)
            SQL;
    }

    /**
     * @return array<string, string>
     */
    private function outcomeParams(): array
    {
        return [
            'export' => AuditEntry::ACTION_EXPORT,
            'blocked' => AuditEntry::OUTCOME_BLOCKED,
            'success' => AuditEntry::OUTCOME_SUCCESS,
            'failed' => AuditEntry::OUTCOME_FAILED,
            'importRunFailed' => AuditEntry::INTENT_IMPORT_RUN_FAILED,
        ];
    }
}
