<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Infrastructure\Dbal;

use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Enum\ClosureFacilityKind;
use App\DataFixtures\Generator\Application\PlannedClosure;
use App\DataFixtures\Pattern\Application\PatternLookupResolver;
use Doctrine\DBAL\Connection;

/**
 * Writes planned closures of one import through DBAL, with every required column (ADR 022).
 */
final readonly class ClosureIntervalWriter
{
    private const array COLUMNS = [
        'starts_at', 'ends_at', 'care_level', 'reason', 'facility_kind', 'source_group_id',
        'source_recorded_at', 'source_changed_at', 'hospital_id', 'import_id', 'speciality_id', 'department_id',
    ];

    public function __construct(
        private Connection $connection,
        private PatternLookupResolver $lookups,
    ) {
    }

    /**
     * @param list<PlannedClosure> $closures
     */
    public function write(array $closures, int $hospitalId, int $importId): void
    {
        if ([] === $closures) {
            return;
        }

        $parameters = [];
        foreach ($closures as $closure) {
            $parameters[] = $closure->startsAt->format('Y-m-d H:i:s');
            $parameters[] = $closure->endsAt->format('Y-m-d H:i:s');
            $parameters[] = $closure->careLevel->value;
            $parameters[] = $closure->reason->value;
            $parameters[] = ClosureFacilityKind::CLINIC->value;
            $parameters[] = $closure->sourceGroupId;
            $parameters[] = $closure->startsAt->format('Y-m-d H:i:s');
            $parameters[] = $closure->endsAt->format('Y-m-d H:i:s');
            $parameters[] = $hospitalId;
            $parameters[] = $importId;
            $parameters[] = $this->lookups->specialityIdFor($closure->department);
            $parameters[] = $this->lookups->resolveId(Department::class, $closure->department);
        }

        $placeholders = '(nextval(\'closure_interval_id_seq\'), '.implode(', ', array_fill(0, \count(self::COLUMNS), '?')).')';
        $this->connection->executeStatement(
            sprintf('INSERT INTO closure_interval (id, %s) VALUES %s', implode(', ', self::COLUMNS), implode(', ', array_fill(0, \count($closures), $placeholders))),
            $parameters,
        );
    }
}
