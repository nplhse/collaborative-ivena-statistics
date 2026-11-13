<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter\Application;

/**
 * The features of one IVENA allocation that the encounter generator needs (#1052).
 *
 * `id` is the allocation's database id; it is what the truth of a generated visit points to.
 */
final readonly class AllocationSnapshot
{
    /**
     * @param string $gender  IVENA gender: `M`, `F` or `X`
     * @param int    $urgency 1–3
     */
    public function __construct(
        public int $id,
        public \DateTimeImmutable $arrivalAt,
        public int $age,
        public string $gender,
        public int $urgency,
        public bool $isWithPhysician,
        public bool $isWorkAccident,
        public bool $isAirTransport = false,
        public ?string $departmentName = null,
    ) {
    }
}
