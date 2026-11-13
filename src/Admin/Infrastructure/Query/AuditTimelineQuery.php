<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Query;

use App\Admin\Application\Audit\AuditEntryDetails;
use App\Admin\Application\Audit\AuditFilterOptions;
use App\Admin\Application\Audit\AuditRequestStats;
use App\Admin\Application\Audit\AuditTimelineCursor;
use App\Admin\Application\Audit\AuditTimelineEntry;
use App\Admin\Application\Audit\AuditTimelineFilter;
use App\Admin\Application\Audit\AuditTimelineReader;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Reads the audit log for the timeline: keyset pages newest first on (occurred_at, id), facts per request,
 * and all entries of one request. Payloads (changes, metadata) are not read; only the names of changed fields.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for AuditTimelineReader.
 */
#[AsAlias(AuditTimelineReader::class)]
final readonly class AuditTimelineQuery implements AuditTimelineReader
{
    private const string COLUMNS = <<<'SQL'
        a.id, a.occurred_at, a.request_id, a.request_id_kind, a.actor_id, u.username AS actor_name,
        a.origin, a.action, a.entity_class, a.entity_id, a.intent, a.outcome, a.session_ref,
        CASE WHEN json_typeof(a.changes) = 'object'
            THEN (SELECT string_agg(k, ',' ORDER BY k) FROM json_object_keys(a.changes) AS k)
        END AS changed_fields
        SQL;

    private const string FROM = 'audit_log a LEFT JOIN "user" u ON u.id = a.actor_id';

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Entries matching the filter, newest first, after the cursor. Returns up to $size entries and whether
     * older entries follow.
     *
     * @return array{entries: list<AuditTimelineEntry>, hasMore: bool}
     */
    #[\Override]
    public function page(AuditTimelineFilter $filter, ?AuditTimelineCursor $after, int $size): array
    {
        [$where, $params, $types] = $this->filterCondition($filter);

        if ($after instanceof AuditTimelineCursor) {
            $where[] = '(a.occurred_at, a.id) < (:cursorAt, :cursorId)';
            $params['cursorAt'] = $after->occurredAt->format('Y-m-d H:i:s');
            $params['cursorId'] = $after->id;
        }

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT %s FROM %s%s ORDER BY a.occurred_at DESC, a.id DESC LIMIT %d',
                self::COLUMNS,
                self::FROM,
                $this->where($where),
                $size + 1,
            ),
            $params,
            $types,
        );

        $entries = array_map($this->mapRow(...), \array_slice($rows, 0, $size));

        return ['entries' => $entries, 'hasMore' => \count($rows) > $size];
    }

    /**
     * Facts about every request id given, counted over all its entries.
     *
     * @param list<string> $requestIds
     *
     * @return array<string, AuditRequestStats>
     */
    #[\Override]
    public function requestStats(array $requestIds, AuditTimelineFilter $filter): array
    {
        if ([] === $requestIds) {
            return [];
        }

        [$where, $params, $types] = $this->filterCondition($filter);
        $matching = [] === $where ? 'COUNT(*)' : 'COUNT(*) FILTER (WHERE '.implode(' AND ', $where).')';
        $params['requestIds'] = array_values(array_unique($requestIds));
        $types['requestIds'] = ArrayParameterType::STRING;

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT a.request_id, COUNT(*) AS total, %s AS matching,
                        COUNT(DISTINCT COALESCE(a.actor_id, 0)) AS actors, COUNT(DISTINCT a.origin) AS origins
                 FROM audit_log a WHERE a.request_id IN (:requestIds) GROUP BY a.request_id',
                $matching,
            ),
            $params,
            $types,
        );

        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row['request_id']] = new AuditRequestStats(
                (int) $row['total'],
                (int) $row['matching'],
                (int) $row['actors'],
                (int) $row['origins'],
            );
        }

        return $stats;
    }

    /**
     * Entries of one request, oldest first, at most $limit.
     *
     * @return list<AuditTimelineEntry>
     */
    #[\Override]
    public function request(string $requestId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT %s FROM %s WHERE a.request_id = :requestId ORDER BY a.occurred_at ASC, a.id ASC LIMIT %d',
                self::COLUMNS,
                self::FROM,
                $limit,
            ),
            ['requestId' => $requestId],
        );

        return array_map($this->mapRow(...), $rows);
    }

    #[\Override]
    public function countNewer(AuditTimelineFilter $filter, AuditTimelineCursor $since, int $limit): int
    {
        [$where, $params, $types] = $this->filterCondition($filter);
        $where[] = '(a.occurred_at, a.id) > (:sinceAt, :sinceId)';
        $params['sinceAt'] = $since->occurredAt->format('Y-m-d H:i:s');
        $params['sinceId'] = $since->id;

        // Bounded: the page only needs to know "some" or "more than $limit".
        return (int) $this->connection->fetchOne(
            \sprintf('SELECT COUNT(*) FROM (SELECT 1 FROM audit_log a%s LIMIT %d) n', self::where($where), $limit),
            $params,
            $types,
        );
    }

    #[\Override]
    public function filterOptions(): AuditFilterOptions
    {
        $actorIds = array_map(intval(...), $this->distinct('actor_id'));
        $names = [] === $actorIds ? [] : $this->connection->fetchAllKeyValue(
            'SELECT id, username FROM "user" WHERE id IN (:ids)',
            ['ids' => $actorIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $actors = array_map(
            static fn (int $id): array => ['id' => $id, 'name' => isset($names[$id]) ? (string) $names[$id] : null],
            $actorIds,
        );
        usort($actors, static fn (array $a, array $b): int => [null === $a['name'], strtolower((string) $a['name']), $a['id']] <=> [null === $b['name'], strtolower((string) $b['name']), $b['id']]);

        return new AuditFilterOptions($this->distinct('intent'), $this->distinct('entity_class'), $actors);
    }

    /**
     * Distinct non-null values of an indexed column, read as a loose index scan: one index lookup per value
     * instead of a scan over all entries.
     *
     * @param 'intent'|'entity_class'|'actor_id' $column
     *
     * @return list<string>
     */
    private function distinct(string $column): array
    {
        /* @var list<string> */
        return array_map(strval(...), $this->connection->fetchFirstColumn(\sprintf(
            'WITH RECURSIVE v AS (
                (SELECT %1$s AS value FROM audit_log WHERE %1$s IS NOT NULL ORDER BY %1$s LIMIT 1)
                UNION ALL
                SELECT (SELECT %1$s FROM audit_log WHERE %1$s > v.value ORDER BY %1$s LIMIT 1) FROM v WHERE v.value IS NOT NULL
            )
            SELECT value FROM v WHERE value IS NOT NULL',
            $column,
        )));
    }

    #[\Override]
    public function details(int $id): ?AuditEntryDetails
    {
        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT %s, a.changes AS changes_json, a.metadata AS metadata_json FROM %s WHERE a.id = :id', self::COLUMNS, self::FROM),
            ['id' => $id],
        );

        if (false === $row) {
            return null;
        }

        return AuditEntryDetails::from($this->mapRow($row), $this->decode($row['changes_json']), $this->decode($row['metadata_json']));
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function decode(mixed $json): ?array
    {
        if (!\is_string($json)) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array{list<string>, array<string, mixed>, array<string, ArrayParameterType>}
     */
    private function filterCondition(AuditTimelineFilter $filter): array
    {
        $where = [];
        $params = [];

        if ($filter->from instanceof \DateTimeImmutable) {
            $where[] = 'a.occurred_at >= :from';
            $params['from'] = $filter->from->format('Y-m-d H:i:s');
        }

        if ($filter->to instanceof \DateTimeImmutable) {
            $where[] = 'a.occurred_at < :to';
            $params['to'] = $filter->to->format('Y-m-d H:i:s');
        }

        if ($filter->withoutActor) {
            $where[] = 'a.actor_id IS NULL';
        } elseif (null !== $filter->actorId) {
            $where[] = 'a.actor_id = :actorId';
            $params['actorId'] = $filter->actorId;
        }

        foreach ([
            'origin' => ['a.origin', $filter->origin],
            'action' => ['a.action', $filter->action],
            'intent' => ['a.intent', $filter->intent],
            'entityClass' => ['a.entity_class', $filter->entityClass],
            'entityId' => ['a.entity_id', $filter->entityId],
            'requestId' => ['a.request_id', $filter->requestId],
            'outcome' => ['a.outcome', $filter->outcome],
        ] as $name => [$column, $value]) {
            if (null !== $value) {
                $where[] = \sprintf('%s = :%s', $column, $name);
                $params[$name] = $value;
            }
        }

        if (null !== $filter->sessionRef) {
            // session_ref is a UUID column; compare as text so a malformed value matches nothing instead of failing.
            $where[] = 'a.session_ref::text = :sessionRef';
            $params['sessionRef'] = strtolower($filter->sessionRef);
        }

        return [$where, $params, []];
    }

    /**
     * @param list<string> $where
     */
    private function where(array $where): string
    {
        return [] === $where ? '' : ' WHERE '.implode(' AND ', $where);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): AuditTimelineEntry
    {
        $occurredAt = new \DateTimeImmutable((string) $row['occurred_at']);
        $changedFields = \is_string($row['changed_fields']) && '' !== $row['changed_fields']
            ? explode(',', $row['changed_fields'])
            : [];

        return new AuditTimelineEntry(
            id: (int) $row['id'],
            occurredAt: $occurredAt,
            requestId: (string) $row['request_id'],
            legacyRequestId: 'legacy' === $row['request_id_kind'],
            actorId: null === $row['actor_id'] ? null : (int) $row['actor_id'],
            actorName: null === $row['actor_name'] ? null : (string) $row['actor_name'],
            origin: (string) $row['origin'],
            action: (string) $row['action'],
            entityClass: (string) $row['entity_class'],
            entityId: null === $row['entity_id'] ? null : (string) $row['entity_id'],
            intent: null === $row['intent'] ? null : (string) $row['intent'],
            outcome: null === $row['outcome'] ? null : (string) $row['outcome'],
            sessionRef: null === $row['session_ref'] ? null : (string) $row['session_ref'],
            changedFields: $changedFields,
        );
    }
}
