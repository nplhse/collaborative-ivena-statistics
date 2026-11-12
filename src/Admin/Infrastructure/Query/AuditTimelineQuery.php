<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Query;

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
