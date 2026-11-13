<?php

declare(strict_types=1);

namespace App\Tests\Admin\Integration\Audit;

use App\Admin\Application\Audit\AuditTimelineCursor;
use App\Admin\Application\Audit\AuditTimelineEntry;
use App\Admin\Application\Audit\AuditTimelineFilter;
use App\Admin\Infrastructure\Query\AuditTimelineQuery;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Fixture (ids in insert order, times on 2026-11-13):
 *
 * | # | time     | request | actor | origin    | action | intent            | outcome |
 * |---|----------|---------|-------|-----------|--------|-------------------|---------|
 * | 1 | 09:00:00 | r1      | alice | http      | update | user.admin.updated|         |
 * | 2 | 09:00:00 | r1      | alice | http      | create |                   |         |
 * | 3 | 09:00:00 | r1      | alice | messenger | update | import.run.failed | failed  |
 * | 4 | 10:00:00 | r2      | -     | cli       | create |                   |         |
 * | 5 | 11:00:00 | r3      | bob   | http      | export |                   | success |
 */
#[ResetDatabase]
final class AuditTimelineQueryTest extends KernelTestCase
{
    use Factories;

    private Connection $connection;

    private AuditTimelineQuery $query;

    /** @var array<int, int> fixture number => database id */
    private array $ids = [];

    private int $alice;

    private int $bob;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->query = self::getContainer()->get(AuditTimelineQuery::class);

        $this->alice = (int) UserFactory::createOne(['username' => 'alice'])->getId();
        $this->bob = (int) UserFactory::createOne(['username' => 'bob'])->getId();
        // Creating the users writes audit entries of its own; the fixture is the only content.
        $this->connection->executeStatement('DELETE FROM audit_log');

        $this->insert(1, '09:00:00', 'r1', $this->alice, 'http', 'update', 'user.admin.updated', null, '{"name":{"old":"a","new":"b"},"email":{"old":"x","new":"y"}}');
        $this->insert(2, '09:00:00', 'r1', $this->alice, 'http', 'create');
        $this->insert(3, '09:00:00', 'r1', $this->alice, 'messenger', 'update', 'import.run.failed', 'failed');
        $this->insert(4, '10:00:00', 'r2', null, 'cli', 'create');
        $this->insert(5, '11:00:00', 'r3', $this->bob, 'http', 'export', null, 'success', '[]');
    }

    public function testPagesNewestFirstWithIdAsTieBreakerAndWithoutGapsOrDuplicates(): void
    {
        $filter = new AuditTimelineFilter();

        $first = $this->query->page($filter, null, 2);
        self::assertSame([5, 4], $this->numbers($first['entries']));
        self::assertTrue($first['hasMore']);

        $second = $this->query->page($filter, AuditTimelineCursor::after($first['entries'][1]), 2);
        self::assertSame([3, 2], $this->numbers($second['entries']), 'same second: higher id first');
        self::assertTrue($second['hasMore']);

        $third = $this->query->page($filter, AuditTimelineCursor::after($second['entries'][1]), 2);
        self::assertSame([1], $this->numbers($third['entries']));
        self::assertFalse($third['hasMore']);
    }

    public function testEntryCarriesActorAndChangedFieldNamesButNoValues(): void
    {
        $entries = $this->query->page(new AuditTimelineFilter(origin: 'http', action: 'update', requestId: 'r1'), null, 10)['entries'];

        self::assertCount(1, $entries);
        self::assertSame('alice', $entries[0]->actorName);
        self::assertSame(['email', 'name'], $entries[0]->changedFields);
        self::assertSame('user.admin.updated', $entries[0]->intent);
        self::assertTrue($entries[0]->legacyRequestId, 'rows without request_id_kind are legacy');
    }

    public function testFilters(): void
    {
        self::assertSame([3, 2, 1], $this->numbersFor(new AuditTimelineFilter(actorId: $this->alice)));
        self::assertSame([4], $this->numbersFor(new AuditTimelineFilter(withoutActor: true)));
        self::assertSame([3], $this->numbersFor(new AuditTimelineFilter(origin: 'messenger')));
        self::assertSame([1], $this->numbersFor(new AuditTimelineFilter(intent: 'user.admin.updated')));
        self::assertSame([5], $this->numbersFor(new AuditTimelineFilter(outcome: 'success')));
        self::assertSame([5], $this->numbersFor(new AuditTimelineFilter(entityClass: 'data_export')));
        self::assertSame([4], $this->numbersFor(new AuditTimelineFilter(
            from: new \DateTimeImmutable('2026-11-13 10:00:00'),
            to: new \DateTimeImmutable('2026-11-13 11:00:00'),
        )), 'from is inclusive, to exclusive');
        self::assertSame([], $this->numbersFor(new AuditTimelineFilter(sessionRef: 'not-a-uuid')));
    }

    public function testRequestStatsCountAllEntriesAndMatchingOnesSeparately(): void
    {
        $stats = $this->query->requestStats(['r1', 'r2', 'unknown'], new AuditTimelineFilter(origin: 'http'));

        self::assertSame(['r1', 'r2'], array_keys($stats));
        self::assertSame(3, $stats['r1']->total);
        self::assertSame(2, $stats['r1']->matching);
        self::assertSame(1, $stats['r1']->distinctActors);
        self::assertSame(2, $stats['r1']->distinctOrigins);
        self::assertSame(1, $stats['r2']->total);
        self::assertSame(0, $stats['r2']->matching);
        self::assertSame([], $this->query->requestStats([], new AuditTimelineFilter()));
    }

    public function testRequestListsEntriesOldestFirstUpToTheLimit(): void
    {
        self::assertSame([1, 2, 3], $this->numbers($this->query->request('r1', 10)));
        self::assertSame([1, 2], $this->numbers($this->query->request('r1', 2)));
        self::assertSame([], $this->query->request('unknown', 10));
    }

    public function testDetailsReadChangesAndMetadataOfOneEntry(): void
    {
        $this->connection->executeStatement(
            "UPDATE audit_log SET metadata = '{\"intent\":\"user.admin.updated\",\"clientIp\":\"1.2.3.4\"}' WHERE id = :id",
            ['id' => $this->ids[1]],
        );

        $details = $this->query->details($this->ids[1]);

        self::assertNotNull($details);
        self::assertSame($this->ids[1], $details->entry->id);
        self::assertSame(['name', 'email'], array_column($details->changes, 'field'));
        self::assertSame(['intent', 'clientIp'], array_column($details->metadata, 'key'));
        self::assertNull($this->query->details(999_999_999));
    }

    /**
     * @return list<int>
     */
    private function numbersFor(AuditTimelineFilter $filter): array
    {
        return $this->numbers($this->query->page($filter, null, 10)['entries']);
    }

    /**
     * @param list<AuditTimelineEntry> $entries
     *
     * @return list<int>
     */
    private function numbers(array $entries): array
    {
        $byId = array_flip($this->ids);

        return array_map(static fn (AuditTimelineEntry $e): int => $byId[$e->id], $entries);
    }

    private function insert(
        int $number,
        string $time,
        string $requestId,
        ?int $actorId,
        string $origin,
        string $action,
        ?string $intent = null,
        ?string $outcome = null,
        string $changes = '{}',
    ): void {
        $this->connection->executeStatement(
            'INSERT INTO audit_log (occurred_at, request_id, actor_id, origin, action, entity_class, entity_id, changes, metadata, intent, outcome)
             VALUES (:at, :rid, :actor, :origin, :action, :class, :entityId, :changes, NULL, :intent, :outcome)',
            [
                'at' => '2026-11-13 '.$time,
                'rid' => $requestId,
                'actor' => $actorId,
                'origin' => $origin,
                'action' => $action,
                'class' => 'export' === $action ? 'data_export' : 'App\Example\Thing',
                'entityId' => (string) $number,
                'changes' => $changes,
                'intent' => $intent,
                'outcome' => $outcome,
            ],
        );
        $this->ids[$number] = (int) $this->connection->lastInsertId();
    }
}
