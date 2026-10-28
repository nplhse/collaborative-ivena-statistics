<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Infrastructure\Dbal;

use App\DataFixtures\Pattern\Dto\SampledAllocationAttributes;
use Doctrine\DBAL\Connection;

/**
 * Writes sampled allocations in batches through DBAL, so a year of realistic volume loads in minutes.
 *
 * Writing past the ORM skips lifecycle callbacks, so every required column is set here (ADR 022).
 */
final class AllocationRowWriter
{
    private const int INSERT_BATCH_SIZE = 1000;

    private const array COLUMNS = [
        'created_at', 'arrival_at', 'gender', 'age', 'urgency', 'transport_type',
        'requires_resus', 'requires_cathlab', 'is_cpr', 'is_ventilated', 'is_shock', 'is_pregnant',
        'is_with_physician', 'is_work_accident', 'department_was_closed',
        'hospital_id', 'dispatch_area_id', 'state_id', 'import_id',
        'speciality_id', 'department_id', 'assignment_id', 'occasion_id', 'infection_id',
        'secondary_transport_id', 'indication_raw_id', 'indication_normalized_id',
    ];

    /** @var list<list<bool|int|string|null>> */
    private array $rows = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param array{hospital_id: int, dispatch_area_id: int, state_id: int, import_id: int} $fixed
     * @param bool                                                                          $departmentWasClosed the department had a closure at the time of the allocation
     */
    public function add(SampledAllocationAttributes $sampled, array $fixed, bool $departmentWasClosed): void
    {
        $this->rows[] = $this->row($sampled, $fixed + ['department_was_closed' => $departmentWasClosed]);
        if (\count($this->rows) >= self::INSERT_BATCH_SIZE) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ([] === $this->rows) {
            return;
        }

        $placeholders = '(nextval(\'allocation_id_seq\'), gen_random_uuid()::text, '.implode(', ', array_fill(0, \count(self::COLUMNS), '?')).')';
        $parameters = [];
        foreach ($this->rows as $row) {
            foreach ($row as $value) {
                $parameters[] = \is_bool($value) ? ($value ? 'true' : 'false') : $value;
            }
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO allocation (id, public_id, %s) VALUES %s',
                implode(', ', self::COLUMNS),
                implode(', ', array_fill(0, \count($this->rows), $placeholders)),
            ),
            $parameters,
        );
        $this->rows = [];
    }

    /**
     * @param array<string, bool|int> $fixed
     *
     * @return list<bool|int|string|null>
     */
    private function row(SampledAllocationAttributes $sampled, array $fixed): array
    {
        $values = [
            'created_at' => $sampled->createdAt->format('Y-m-d H:i:s'),
            'arrival_at' => $sampled->arrivalAt->format('Y-m-d H:i:s'),
            'gender' => $sampled->gender->value,
            'age' => $sampled->age,
            'urgency' => $sampled->urgency->value,
            'transport_type' => $sampled->transportType?->value,
            'requires_resus' => $sampled->requiresResus,
            'requires_cathlab' => $sampled->requiresCathlab,
            'is_cpr' => $sampled->isCpr,
            'is_ventilated' => $sampled->isVentilated,
            'is_shock' => $sampled->isShock,
            'is_pregnant' => $sampled->isPregnant,
            'is_with_physician' => $sampled->isWithPhysician,
            'is_work_accident' => $sampled->isWorkAccident,
            'speciality_id' => $sampled->speciality->getId(),
            'department_id' => $sampled->department->getId(),
            'assignment_id' => $sampled->assignment->getId(),
            'occasion_id' => $sampled->occasion->getId(),
            'infection_id' => $sampled->infection?->getId(),
            'secondary_transport_id' => $sampled->secondaryTransport?->getId(),
            'indication_raw_id' => $sampled->indicationRaw->getId(),
            'indication_normalized_id' => $sampled->indicationNormalized?->getId(),
        ] + $fixed;

        return array_map(static fn (string $column): bool|int|string|null => $values[$column], self::COLUMNS);
    }
}
