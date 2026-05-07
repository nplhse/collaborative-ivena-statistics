<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\UI\Http\DTO\AllocationQueryParametersDTO;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ListAllocationsQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CursorCodec $cursorCodec,
    ) {
    }

    public function getPaginator(AllocationQueryParametersDTO $queryParametersDTO): CursorPaginator
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('a.id, a.createdAt, a.arrivalAt, s.id as state_id, s.name as state, da.id as dispatchArea_id, da.name as dispatchArea,
                h.id as hospital_id, h.name as hospital, h.tier, h.size, h.location, a.gender, a.age, a.requiresResus, a.requiresCathlab, a.isCPR, a.isVentilated, a.isShock,
                a.isPregnant, a.isWorkAccident, a.isWithPhysician, a.urgency, i.name as infection, iraw.name as indicationRawName, iraw.code indicationRawCode,
                inor.name as indicationNormalizedName, inor.code as indicationNormalizedCode,
                iraw2.name as secondaryIndicationRawName, iraw2.code as secondaryIndicationRawCode,
                inor2.name as secondaryIndicationNormalizedName, inor2.code as secondaryIndicationNormalizedCode')
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

        if (null !== $queryParametersDTO->importId) {
            $qb->andWhere('a.import = :importId')
            ->setParameter('importId', $queryParametersDTO->importId);
        }

        $field = match ($queryParametersDTO->sortBy) {
            'arrivalAt' => 'a.arrivalAt',
            'age' => 'a.age',
            default => 'h.'.$queryParametersDTO->sortBy,
        };

        if (null !== $queryParametersDTO->tier && '' !== $queryParametersDTO->tier) {
            $qb->andWhere('h.tier = :tier')
                ->setParameter('tier', HospitalTier::from($queryParametersDTO->tier));
        }

        if (null !== $queryParametersDTO->location && '' !== $queryParametersDTO->location) {
            $qb->andWhere('h.location = :location')
                ->setParameter('location', HospitalLocation::from($queryParametersDTO->location));
        }

        if (null !== $queryParametersDTO->size && '' !== $queryParametersDTO->size) {
            $qb->andWhere('h.size = :size')
                ->setParameter('size', HospitalSize::from($queryParametersDTO->size));
        }

        if (null !== $queryParametersDTO->urgency && '' !== $queryParametersDTO->urgency) {
            $qb->andWhere('a.urgency = :urgency')
                ->setParameter('urgency', AllocationUrgency::from($queryParametersDTO->urgency));
        }

        if (null !== $queryParametersDTO->state) {
            $qb->andWhere('s.id = :stateId')
                ->setParameter('stateId', $queryParametersDTO->state);
        }

        if (null !== $queryParametersDTO->dispatchArea) {
            $qb->andWhere('da.id = :dispatchAreaId')
                ->setParameter('dispatchAreaId', $queryParametersDTO->dispatchArea);
        }

        if (null !== $queryParametersDTO->requiresResus) {
            $qb->andWhere('a.requiresResus = :requiresResus')
                ->setParameter(
                    'requiresResus',
                    filter_var($queryParametersDTO->requiresResus, FILTER_VALIDATE_BOOLEAN)
                );
        }

        if (null !== $queryParametersDTO->requiresCathlab) {
            $qb->andWhere('a.requiresCathlab = :requiresCathlab')
                ->setParameter(
                    'requiresCathlab',
                    filter_var($queryParametersDTO->requiresCathlab, FILTER_VALIDATE_BOOLEAN)
                );
        }

        if (null !== $queryParametersDTO->indication) {
            $qb->andWhere('inor.code = :indication')
                ->setParameter('indication', $queryParametersDTO->indication);
        }

        if (null !== $queryParametersDTO->secondaryTransport) {
            $qb->andWhere('st.id = :secondaryTransportId')
                ->setParameter('secondaryTransportId', $queryParametersDTO->secondaryTransport);
        }

        $estimatedNumResults = $this->estimateNumResults($qb);

        $cursorPayload = null;
        if (null !== $queryParametersDTO->cursor) {
            try {
                $decodedCursor = $this->cursorCodec->decode($queryParametersDTO->cursor);
                if (
                    $decodedCursor['sortBy'] === $queryParametersDTO->sortBy
                    && $decodedCursor['orderBy'] === $queryParametersDTO->orderBy
                ) {
                    $cursorPayload = $decodedCursor;
                }
            } catch (\InvalidArgumentException) {
                $cursorPayload = null;
            }
        }

        if (null !== $cursorPayload) {
            $sortValue = 'age' === $queryParametersDTO->sortBy
                ? (int) $cursorPayload['sortValue']
                : new \DateTimeImmutable((string) $cursorPayload['sortValue']);

            $isDescending = 'desc' === $queryParametersDTO->orderBy;
            $mainComparison = $isDescending
                ? $qb->expr()->lt($field, ':cursorSortValue')
                : $qb->expr()->gt($field, ':cursorSortValue');

            $qb->andWhere(
                $qb->expr()->orX(
                    $mainComparison,
                    $qb->expr()->andX(
                        $qb->expr()->eq($field, ':cursorSortValue'),
                        $isDescending
                            ? $qb->expr()->lt('a.id', ':cursorId')
                            : $qb->expr()->gt('a.id', ':cursorId')
                    )
                )
            )
                ->setParameter(
                    'cursorSortValue',
                    $sortValue,
                    'age' === $queryParametersDTO->sortBy ? ParameterType::INTEGER : Types::DATETIME_IMMUTABLE
                )
                ->setParameter('cursorId', $cursorPayload['id'], ParameterType::INTEGER);
        }

        $qb
            ->orderBy($field, $queryParametersDTO->orderBy)
            ->addOrderBy('a.id', $queryParametersDTO->orderBy)
            ->setMaxResults($queryParametersDTO->limit + 1);

        /** @var list<array<string, mixed>> $results */
        $results = $qb->getQuery()->getArrayResult();
        $hasNextPage = \count($results) > $queryParametersDTO->limit;
        $visibleResults = \array_slice($results, 0, $queryParametersDTO->limit);

        $nextCursor = null;
        if ($hasNextPage && [] !== $visibleResults) {
            $lastItem = $visibleResults[\array_key_last($visibleResults)];
            $sortValue = 'age' === $queryParametersDTO->sortBy
                ? (int) $lastItem['age']
                : $this->formatDateTimeValue($lastItem['arrivalAt']);

            $nextCursor = $this->cursorCodec->encode(
                $queryParametersDTO->sortBy,
                $queryParametersDTO->orderBy,
                $sortValue,
                (int) $lastItem['id'],
            );
        }

        return new CursorPaginator(
            $visibleResults,
            $queryParametersDTO->limit,
            $nextCursor,
            null,
            $estimatedNumResults,
        );
    }

    private function formatDateTimeValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return (string) $value;
    }

    private function estimateNumResults(\Doctrine\ORM\QueryBuilder $qb): ?int
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
