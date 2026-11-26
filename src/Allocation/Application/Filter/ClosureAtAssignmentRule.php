<?php

declare(strict_types=1);

namespace App\Allocation\Application\Filter;

use App\Allocation\Domain\Entity\ClosureInterval;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\ClosureAtAssignment;
use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use Doctrine\ORM\QueryBuilder;

/**
 * The one definition of "closed at assignment" from closure imports (#571).
 *
 * - closed: an interval of a completed or partial closure import has the allocation's
 *   hospital and department, `starts_at <= created_at < ends_at`, and a care level that
 *   closes the allocation's SK ({@see ClosureCareLevel::closesUrgencies()}).
 * - open: not closed, and a completed or partial closure import of the hospital covers
 *   `created_at` with its export period `[export_starts_at, export_ends_at)`.
 * - unknown: neither; an import without an export period covers nothing.
 */
final class ClosureAtAssignmentRule
{
    public static function fromQuery(?string $value): ?ClosureAtAssignment
    {
        return null === $value ? null : ClosureAtAssignment::tryFrom($value);
    }

    public static function apply(QueryBuilder $qb, string $alias, ClosureAtAssignment $state): void
    {
        $closed = self::closedDql($alias);
        $covered = self::coveredDql($alias);

        match ($state) {
            ClosureAtAssignment::CLOSED => $qb->andWhere(sprintf('EXISTS (%s)', $closed)),
            ClosureAtAssignment::OPEN => $qb->andWhere(sprintf('NOT EXISTS (%s)', $closed))
                ->andWhere(sprintf('EXISTS (%s)', $covered)),
            ClosureAtAssignment::UNKNOWN => $qb->andWhere(sprintf('NOT EXISTS (%s)', $closed))
                ->andWhere(sprintf('NOT EXISTS (%s)', $covered)),
        };
    }

    /**
     * DQL expression that yields the {@see ClosureAtAssignment} value of the allocation alias.
     */
    public static function stateDql(string $alias): string
    {
        return sprintf(
            "CASE WHEN EXISTS (%s) THEN '%s' WHEN EXISTS (%s) THEN '%s' ELSE '%s' END",
            self::closedDql($alias),
            ClosureAtAssignment::CLOSED->value,
            self::coveredDql($alias),
            ClosureAtAssignment::OPEN->value,
            ClosureAtAssignment::UNKNOWN->value,
        );
    }

    /**
     * Care level to SK condition, valid in SQL and DQL.
     *
     * @param string $careLevel expression of the care level value
     * @param string $urgency   expression of the urgency code (1–3)
     */
    public static function careLevelClosesUrgency(string $careLevel, string $urgency): string
    {
        $conditions = array_map(
            static fn (ClosureCareLevel $level): string => sprintf(
                "(%s = '%s' AND %s IN (%s))",
                $careLevel,
                $level->value,
                $urgency,
                implode(', ', array_map(static fn (AllocationUrgency $code): int => $code->value, $level->closesUrgencies())),
            ),
            ClosureCareLevel::cases(),
        );

        return '('.implode(' OR ', $conditions).')';
    }

    private static function closedDql(string $alias): string
    {
        return sprintf(
            'SELECT caa_ci.id FROM %1$s caa_ci JOIN caa_ci.import caa_ci_import'
            .' WHERE caa_ci.hospital = %2$s.hospital AND caa_ci.department = %2$s.department'
            .' AND caa_ci.startsAt <= %2$s.createdAt AND caa_ci.endsAt > %2$s.createdAt'
            .' AND caa_ci_import.status IN (%3$s) AND %4$s',
            ClosureInterval::class,
            $alias,
            self::usedImportStatuses(),
            self::careLevelClosesUrgency('caa_ci.careLevel', $alias.'.urgency'),
        );
    }

    private static function coveredDql(string $alias): string
    {
        return sprintf(
            'SELECT caa_cov.id FROM %1$s caa_cov'
            .' WHERE caa_cov.hospital = %2$s.hospital AND caa_cov.type = \'%3$s\' AND caa_cov.status IN (%4$s)'
            .' AND caa_cov.exportStartsAt <= %2$s.createdAt AND caa_cov.exportEndsAt > %2$s.createdAt',
            Import::class,
            $alias,
            ImportType::CLOSURE->value,
            self::usedImportStatuses(),
        );
    }

    /** Only completed and partial imports feed closure data, as in the closure analysis. */
    private static function usedImportStatuses(): string
    {
        return sprintf("'%s', '%s'", ImportStatus::COMPLETED->value, ImportStatus::PARTIAL->value);
    }
}
