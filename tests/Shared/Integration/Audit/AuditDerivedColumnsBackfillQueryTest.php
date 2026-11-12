<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Audit;

use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\Shared\Infrastructure\Audit\Query\AuditDerivedColumnsBackfillQuery;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Rows are inserted the way entries looked before ADR 025: no intent, no outcome, request_id_kind from the
 * column default. The SQL backfill must reach the same values as AuditEntry::intentFrom()/outcomeFrom().
 */
#[ResetDatabase]
final class AuditDerivedColumnsBackfillQueryTest extends KernelTestCase
{
    private Connection $connection;

    private AuditDerivedColumnsBackfillQuery $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->query = self::getContainer()->get(AuditDerivedColumnsBackfillQuery::class);
    }

    /**
     * label => [action, metadata].
     *
     * @return array<string, array{string, array<string, mixed>|null}>
     */
    private function legacyRows(): array
    {
        return [
            'no metadata' => ['create', null],
            'intent' => ['update', ['intent' => 'user.admin.updated', 'clientIp' => '1.2.3.4']],
            'empty intent' => ['update', ['intent' => '']],
            'array intent' => ['update', ['intent' => ['x']]],
            'failed import run' => ['update', ['intent' => 'import.run.failed', 'intent_metadata' => ['reason' => 'boom']]],
            'finished import run' => ['update', ['intent' => 'import.run.finished']],
            'export ok' => ['export', ['blocked' => false, 'success' => true]],
            'export failed' => ['export', ['blocked' => false, 'success' => false]],
            'export blocked' => ['export', ['blocked' => true, 'success' => false]],
            'export without flags' => ['export', ['rowCount' => 1]],
            'string flags are not booleans' => ['export', ['blocked' => 'true', 'success' => 'true']],
            'download with success key' => ['download', ['intent' => 'import.source_file.downloaded', 'success' => true]],
        ];
    }

    public function testBackfillMatchesTheEntityRulesAndRunsOnlyOnce(): void
    {
        foreach ($this->legacyRows() as $label => [$action, $metadata]) {
            $this->insertLegacyRow($label, $action, $metadata);
        }

        // intent: user.admin.updated, import.run.failed, import.run.finished, import.source_file.downloaded
        self::assertSame(4, $this->query->countMissingIntent());
        // outcome: failed import run, export ok, export failed, export blocked
        self::assertSame(4, $this->query->countMissingOutcome());

        self::assertSame(4, $this->query->fillIntent());
        self::assertSame(4, $this->query->fillOutcome());

        foreach ($this->legacyRows() as $label => [$action, $metadata]) {
            $row = $this->connection->fetchAssociative(
                'SELECT intent, outcome, request_id_kind, session_ref FROM audit_log WHERE entity_id = :label',
                ['label' => $label],
            );
            self::assertIsArray($row);
            self::assertSame(AuditEntry::intentFrom($metadata), $row['intent'], $label);
            self::assertSame(AuditEntry::outcomeFrom($action, $metadata), $row['outcome'], $label);
            self::assertSame(AuditEntry::REQUEST_ID_LEGACY, $row['request_id_kind'], $label);
            self::assertNull($row['session_ref'], $label);
        }

        self::assertSame(0, $this->query->countMissingIntent());
        self::assertSame(0, $this->query->countMissingOutcome());
        self::assertSame(0, $this->query->fillIntent());
        self::assertSame(0, $this->query->fillOutcome());
    }

    public function testBackfillLeavesEverythingElseUnchanged(): void
    {
        $this->insertLegacyRow('snapshot', 'update', ['intent' => 'import.run.failed', 'clientIp' => '1.2.3.4']);
        $sql = 'SELECT occurred_at, request_id, actor_id, origin, action, entity_class, entity_id, changes::text, metadata::text FROM audit_log';
        $before = $this->connection->fetchAllAssociative($sql);

        $this->query->fillIntent();
        $this->query->fillOutcome();

        self::assertSame($before, $this->connection->fetchAllAssociative($sql));
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function insertLegacyRow(string $label, string $action, ?array $metadata): void
    {
        $this->connection->executeStatement(
            'INSERT INTO audit_log (occurred_at, request_id, actor_id, origin, action, entity_class, entity_id, changes, metadata)
             VALUES (:at, :rid, NULL, :origin, :action, :class, :label, :changes, :metadata)',
            [
                'at' => '2026-06-01 10:00:00',
                'rid' => 'legacy-'.md5($label),
                'origin' => 'http',
                'action' => $action,
                'class' => 'App\Example',
                'label' => $label,
                'changes' => '{"name":{"old":"a","new":"b"}}',
                'metadata' => null === $metadata ? null : json_encode($metadata, \JSON_THROW_ON_ERROR),
            ],
        );
    }
}
