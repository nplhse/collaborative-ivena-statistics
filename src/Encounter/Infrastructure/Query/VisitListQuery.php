<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\VisitListReaderInterface;
use App\Encounter\Application\VisitList\VisitLinkState;
use App\Encounter\Application\VisitList\VisitListFilter;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\MtsCategory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Visit list of one hospital (#1178). One row per visit: the effective link is joined (the partial unique index
 * `uniq_encounter_match_effective_encounter` allows at most one), open proposals are only tested with EXISTS,
 * never joined. The period uses the matching anchor of the review (ED admission, else first assessment).
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of VisitListReaderInterface.
 */
final readonly class VisitListQuery implements VisitListReaderInterface
{
    private const string ANCHOR = 'COALESCE(v.ed_admission_at, v.first_assessment_contact_at)';

    private const string EFFECTIVE = "EXISTS (SELECT 1 FROM encounter_match l WHERE l.encounter_id = e.id AND l.status IN ('auto_accepted', 'confirmed'))";

    private const string PROPOSED = "EXISTS (SELECT 1 FROM encounter_match p WHERE p.encounter_id = e.id AND p.status = 'proposed')";

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function visits(VisitListFilter $filter, string $sortBy, string $orderBy, int $offset, int $limit): array
    {
        [$where, $parameters, $types] = $this->where($filter);
        $state = $filter->includeProposals
            ? sprintf("CASE WHEN m.id IS NOT NULL THEN 'linked' WHEN %s THEN 'pending' ELSE 'unlinked' END", self::PROPOSED)
            : "CASE WHEN m.id IS NOT NULL THEN 'linked' ELSE 'unlinked' END";
        $order = sprintf('%s %s, e.id %2$s', 'age' === $sortBy ? 'e.age' : self::ANCHOR, 'desc' === $orderBy ? 'DESC' : 'ASC');
        $anchor = self::ANCHOR;

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
SELECT e.id, v.visit_uid, {$anchor} AS anchor_at, v.ed_admission_at IS NULL AS substitute, e.age, e.sex,
       v.arrival_route, v.arrival_mode, v.mts_category, v.mts_diagram, v.disposition_code, v.referral_source,
       v.case_status, d.name AS department, {$state} AS link_state, a.id AS allocation_id, a.public_id AS allocation_public_id
FROM encounter e JOIN ed_visit v ON v.encounter_id = e.id
LEFT JOIN department d ON d.id = v.admitting_department_id
LEFT JOIN encounter_match m ON m.encounter_id = e.id AND m.status IN ('auto_accepted', 'confirmed')
LEFT JOIN allocation a ON a.id = m.allocation_id
WHERE {$where}
ORDER BY {$order}
LIMIT :limit OFFSET :offset
SQL,
            [...$parameters, 'limit' => $limit, 'offset' => $offset],
            [...$types, 'limit' => Types::INTEGER, 'offset' => Types::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'encounter_id' => (int) $row['id'],
            'visit_uid' => (string) $row['visit_uid'],
            'anchor_at' => new \DateTimeImmutable((string) $row['anchor_at']),
            'substitute' => (bool) $row['substitute'],
            'age' => (int) $row['age'],
            'sex' => (string) $row['sex'],
            'arrival_route' => (string) $row['arrival_route'],
            'arrival_mode' => self::nullableString($row['arrival_mode']),
            'mts_category' => self::nullableString($row['mts_category']),
            'mts_diagram' => self::nullableString($row['mts_diagram']),
            'disposition' => self::nullableString($row['disposition_code']),
            'referral_source' => self::nullableString($row['referral_source']),
            'case_status' => self::nullableString($row['case_status']),
            'department' => self::nullableString($row['department']),
            'link_state' => (string) $row['link_state'],
            'allocation_id' => null === $row['allocation_id'] ? null : (int) $row['allocation_id'],
            'allocation_public_id' => self::nullableString($row['allocation_public_id']),
        ], $rows);
    }

    #[\Override]
    public function countVisits(VisitListFilter $filter): int
    {
        [$where, $parameters, $types] = $this->where($filter);

        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM encounter e JOIN ed_visit v ON v.encounter_id = e.id WHERE {$where}", $parameters, $types);
    }

    #[\Override]
    public function hospitals(?array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        $sql = 'SELECT h.id, h.public_id, h.name FROM hospital h WHERE '
            .(null === $hospitalIds ? 'EXISTS (SELECT 1 FROM encounter e JOIN ed_visit v ON v.encounter_id = e.id WHERE e.hospital_id = h.id)' : 'h.id IN (:ids)')
            .' ORDER BY h.name, h.id';

        /** @var list<array{id: int|string, public_id: string, name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, ['ids' => $hospitalIds ?? []], ['ids' => ArrayParameterType::INTEGER]);

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'public_id' => $row['public_id'], 'name' => $row['name']], $rows);
    }

    #[\Override]
    public function diagramCodes(int $hospitalId): array
    {
        /** @var list<string> $codes */
        $codes = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT v.mts_diagram FROM encounter e JOIN ed_visit v ON v.encounter_id = e.id WHERE e.hospital_id = :hospital AND v.mts_diagram IS NOT NULL ORDER BY v.mts_diagram',
            ['hospital' => $hospitalId],
            ['hospital' => Types::INTEGER],
        );

        return $codes;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function where(VisitListFilter $filter): array
    {
        // The fingerprint anchor (indexed) lies at most a day away from the matching anchor.
        $conditions = [
            'e.hospital_id = :hospital',
            "e.anchor_at BETWEEN CAST(:from AS TIMESTAMP) - INTERVAL '1 day' AND CAST(:to AS TIMESTAMP) + INTERVAL '1 day'",
            self::ANCHOR.' >= :from',
            self::ANCHOR.' < :to',
        ];
        $parameters = ['hospital' => $filter->hospitalId, 'from' => $filter->period->from, 'to' => $filter->period->toExclusive()];
        $types = ['hospital' => Types::INTEGER, 'from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE];

        if ([] !== $filter->routes) {
            $conditions[] = 'v.arrival_route IN (:routes)';
            $parameters['routes'] = array_map(static fn (ArrivalRoute $route): string => $route->value, $filter->routes);
            $types['routes'] = ArrayParameterType::STRING;
        }

        if ([] !== $filter->categories) {
            $conditions[] = 'v.mts_category IN (:categories)';
            $parameters['categories'] = array_map(static fn (MtsCategory $category): string => $category->value, $filter->categories);
            $types['categories'] = ArrayParameterType::STRING;
        }

        if (null !== $filter->diagram) {
            $conditions[] = 'v.mts_diagram = :diagram';
            $parameters['diagram'] = $filter->diagram;
            $types['diagram'] = Types::STRING;
        }

        if ($filter->disposition instanceof \App\Encounter\Domain\Enum\Disposition) {
            $conditions[] = 'v.disposition_code = :disposition';
            $parameters['disposition'] = $filter->disposition->value;
            $types['disposition'] = Types::STRING;
        }

        $conditions[] = match ($filter->linkState) {
            VisitLinkState::Linked => self::EFFECTIVE,
            VisitLinkState::Pending => 'NOT '.self::EFFECTIVE.' AND '.self::PROPOSED,
            VisitLinkState::Unlinked => 'NOT '.self::EFFECTIVE.($filter->includeProposals ? ' AND NOT '.self::PROPOSED : ''),
            null => 'TRUE',
        };

        return [implode(' AND ', $conditions), $parameters, $types];
    }

    private static function nullableString(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
