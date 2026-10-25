<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

use App\Allocation\Application\Allocations\AllocationListFilterCriteriaFactory;
use App\Allocation\Application\Export\AllocationListFilterApplicator;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\State;
use App\Allocation\UI\Http\DTO\AllocationQueryParametersDTO;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class ListAllocationsQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CursorCodec $cursorCodec,
        private AllocationListFilterApplicator $filterApplicator,
        private AllocationListFilterCriteriaFactory $filterCriteriaFactory,
    ) {
    }

    /**
     * Optional columns that need their own join, keyed by column key: [relation, alias].
     * They are read for the rows of the current page only, so the keyset query keeps its plan.
     *
     * @var array<string, array{string, string}>
     */
    private const array OPTIONAL_JOINS = [
        'speciality' => ['a.speciality', 'spec'],
        'department' => ['a.department', 'dep'],
        'assignment' => ['a.assignment', 'asg'],
        'occasion' => ['a.occasion', 'occ'],
    ];

    /**
     * @param list<string> $optionalColumns visible optional columns; only these are joined
     * @param int|null     $pageSize        page size to use instead of the query's `limit` (a saved preference)
     */
    public function getPaginator(
        AllocationQueryParametersDTO $queryParametersDTO,
        ?User $user = null,
        array $optionalColumns = [],
        ?int $pageSize = null,
    ): CursorPaginator {
        $limit = max(1, $pageSize ?? $queryParametersDTO->limit);
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('a.id, a.publicId, a.createdAt, a.arrivalAt, a.departmentWasClosed, s.id as state_id, s.name as state, da.id as dispatchArea_id, da.name as dispatchArea,
                h.id as hospital_id, h.publicId as hospital_public_id, h.name as hospital, h.tier, h.size, h.location, a.gender, a.age, a.requiresResus, a.requiresCathlab, a.isCPR, a.isVentilated, a.isShock,
                a.isPregnant, a.isWorkAccident, a.isWithPhysician, a.urgency, i.name as infection, iraw.name as indicationRawName, iraw.code indicationRawCode,
                inor.name as indicationNormalizedName, inor.code as indicationNormalizedCode,
                iraw2.name as secondaryIndicationRawName, iraw2.code as secondaryIndicationRawCode,
                inor2.name as secondaryIndicationNormalizedName, inor2.code as secondaryIndicationNormalizedCode,
                a.transportType, st.name as secondaryTransport')
            ->from(Allocation::class, 'a')
            ->leftJoin(
                State::class,
                's',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'a.state = s.id'
            )
            ->leftJoin(
                DispatchArea::class,
                'da',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'a.dispatchArea = da.id'
            )
            ->leftJoin(
                Hospital::class,
                'h',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'a.hospital = h.id'
            )
            ->leftJoin(
                Infection::class,
                'i',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'a.infection = i.id'
            )
            ->leftJoin(
                SecondaryTransport::class,
                'st',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'a.secondaryTransport = st.id'
            )
            ->leftJoin(
                IndicationRaw::class,
                'iraw',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'a.indicationRaw = iraw.id'
            )
            ->leftJoin(
                IndicationNormalized::class,
                'inor',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'a.indicationNormalized = inor.id'
            )
            ->leftJoin(
                IndicationRaw::class,
                'iraw2',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'a.secondaryIndicationRaw = iraw2.id'
            )
            ->leftJoin(
                IndicationNormalized::class,
                'inor2',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                'a.secondaryIndicationNormalized = inor2.id'
            );

        $sortField = match ($queryParametersDTO->sortBy) {
            'arrivalAt' => 'a.arrivalAt',
            'age' => 'a.age',
            default => 'a.arrivalAt',
        };

        $this->filterApplicator->apply($qb, $this->filterCriteriaFactory->fromQuery($queryParametersDTO, $user));

        $estimatedNumResults = $this->estimateNumResults($qb);

        $cursor = $this->decodeCursor($queryParametersDTO);
        $slice = $this->fetchSlice($qb, $queryParametersDTO, $sortField, $cursor, $limit);

        // Paging back reached the first rows but found fewer than a page (rows were
        // deleted in between): show a regular, full first page instead.
        if (null !== $cursor && !$cursor['forward'] && !$slice['hasMore'] && \count($slice['rows']) < $limit) {
            $cursor = null;
            $slice = $this->fetchSlice($qb, $queryParametersDTO, $sortField, null, $limit);
        }

        $rows = $this->withOptionalColumns($slice['rows'], $optionalColumns);
        $nextCursor = null;
        $previousCursor = null;
        if ([] !== $rows) {
            $isBackward = null !== $cursor && !$cursor['forward'];
            $hasNextPage = $isBackward || $slice['hasMore'];
            $hasPreviousPage = $isBackward ? $slice['hasMore'] : null !== $cursor;

            if ($hasNextPage) {
                $nextCursor = $this->encodeCursor($queryParametersDTO, $rows[\array_key_last($rows)], true);
            }
            if ($hasPreviousPage) {
                $previousCursor = $this->encodeCursor($queryParametersDTO, $rows[0], false);
            }
        }

        return new CursorPaginator(
            $rows,
            $limit,
            $nextCursor,
            $previousCursor,
            $estimatedNumResults,
            null !== $cursor ? $queryParametersDTO->cursor : null,
        );
    }

    /**
     * Adds the names of the visible optional columns to the page rows with one query by id.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $optionalColumns
     *
     * @return list<array<string, mixed>>
     */
    private function withOptionalColumns(array $rows, array $optionalColumns): array
    {
        $joins = array_intersect_key(self::OPTIONAL_JOINS, array_flip($optionalColumns));
        if ([] === $rows || [] === $joins) {
            return $rows;
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('a.id')
            ->from(Allocation::class, 'a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (array $row): mixed => $row['id'], $rows));
        foreach ($joins as $column => [$relation, $alias]) {
            $qb->leftJoin($relation, $alias)->addSelect(\sprintf('%s.name AS %s', $alias, $column));
        }

        $names = [];
        /** @var array<string, mixed> $row */
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $names[(int) $row['id']] = $row;
        }

        return array_map(
            static fn (array $row): array => [...$row, ...($names[(int) $row['id']] ?? [])],
            $rows,
        );
    }

    /**
     * @return array{sortValue: string|int, id: int, forward: bool}|null
     */
    private function decodeCursor(AllocationQueryParametersDTO $queryParametersDTO): ?array
    {
        if (null === $queryParametersDTO->cursor || '' === $queryParametersDTO->cursor) {
            return null;
        }

        try {
            $decoded = $this->cursorCodec->decode($queryParametersDTO->cursor);
        } catch (\InvalidArgumentException) {
            return null;
        }

        // A cursor from another sort order points nowhere useful: start over.
        if ($decoded['sortBy'] !== $queryParametersDTO->sortBy || $decoded['orderBy'] !== $queryParametersDTO->orderBy) {
            return null;
        }

        return ['sortValue' => $decoded['sortValue'], 'id' => $decoded['id'], 'forward' => $decoded['forward']];
    }

    /**
     * Fetches one page after (forward) or before (backward) the cursor row, in display order.
     *
     * @param array{sortValue: string|int, id: int, forward: bool}|null $cursor
     *
     * @return array{rows: list<array<string, mixed>>, hasMore: bool}
     */
    private function fetchSlice(QueryBuilder $source, AllocationQueryParametersDTO $queryParametersDTO, string $sortField, ?array $cursor, int $limit): array
    {
        $qb = clone $source;
        $forward = null === $cursor || $cursor['forward'];
        // Paging backward walks the index the other way and reverses the rows afterwards.
        $ascending = ('asc' === $queryParametersDTO->orderBy) === $forward;

        if (null !== $cursor) {
            $isAge = 'age' === $queryParametersDTO->sortBy;
            $strict = $ascending ? '>' : '<';
            $bound = $ascending ? '>=' : '<=';

            // The leading bound lets PostgreSQL start the index scan at the cursor row.
            // Without it, the OR alone makes it scan and discard every row before the cursor.
            $qb->andWhere(sprintf(
                '%1$s %2$s :cursorSortValue AND (%1$s %3$s :cursorSortValue OR (%1$s = :cursorSortValue AND a.id %3$s :cursorId))',
                $sortField,
                $bound,
                $strict,
            ))
                ->setParameter(
                    'cursorSortValue',
                    $isAge ? (int) $cursor['sortValue'] : new \DateTimeImmutable((string) $cursor['sortValue']),
                    $isAge ? ParameterType::INTEGER : Types::DATETIME_IMMUTABLE,
                )
                ->setParameter('cursorId', $cursor['id'], ParameterType::INTEGER);
        }

        $direction = $ascending ? 'ASC' : 'DESC';
        $qb
            ->orderBy($sortField, $direction)
            ->addOrderBy('a.id', $direction)
            ->setMaxResults($limit + 1);

        /** @var list<array<string, mixed>> $results */
        $results = $qb->getQuery()->getArrayResult();
        $hasMore = \count($results) > $limit;
        $rows = \array_slice($results, 0, $limit);

        return [
            'rows' => $forward ? $rows : array_reverse($rows),
            'hasMore' => $hasMore,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function encodeCursor(AllocationQueryParametersDTO $queryParametersDTO, array $row, bool $forward): string
    {
        $sortValue = 'age' === $queryParametersDTO->sortBy
            ? (int) $row['age']
            : $this->formatDateTimeValue($row['arrivalAt']);

        return $this->cursorCodec->encode(
            $queryParametersDTO->sortBy,
            $queryParametersDTO->orderBy,
            $sortValue,
            (int) $row['id'],
            $forward,
        );
    }

    private function formatDateTimeValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return (string) $value;
    }

    private function estimateNumResults(QueryBuilder $qb): ?int
    {
        $estimateQb = clone $qb;
        $estimateQb
            ->resetDQLPart('orderBy')
            ->select('a.id');

        $query = $estimateQb->getQuery();
        $rawSql = $query->getSQL();
        $explainSql = \is_array($rawSql) ? implode(' ', $rawSql) : $rawSql;
        $connection = $this->entityManager->getConnection();

        try {
            foreach ($query->getParameters() as $parameter) {
                $quoted = $this->quoteForExplain($parameter->getValue());
                $explainSql = preg_replace('/\?/', $quoted, $explainSql, 1) ?? $explainSql;
            }

            $rows = $connection
                ->executeQuery('EXPLAIN '.$explainSql)
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return null;
        }

        return $this->extractEstimatedRows($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function extractEstimatedRows(array $rows): ?int
    {
        foreach ($rows as $row) {
            if (isset($row['rows']) && \is_numeric($row['rows'])) {
                return (int) $row['rows'];
            }

            if (isset($row['QUERY PLAN']) && \is_string($row['QUERY PLAN']) && 1 === preg_match('/rows=(\d+)/', $row['QUERY PLAN'], $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function quoteForExplain(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return $this->quoteForExplain($value->value);
        }

        if ($value instanceof \DateTimeInterface) {
            return "'".$value->format('Y-m-d H:i:s')."'";
        }

        if (\is_array($value)) {
            $quoted = array_map($this->quoteForExplain(...), $value);

            return '('.implode(', ', $quoted).')';
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (null === $value) {
            return 'NULL';
        }

        $escaped = str_replace("'", "''", (string) $value);

        return "'".$escaped."'";
    }
}
