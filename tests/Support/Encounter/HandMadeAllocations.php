<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter;

use App\DataFixtures\Encounter\Application\AllocationSnapshot;

/**
 * 100 hand-made allocations for the encounter generator (#1052): one every 7 hours from 1 October 2026
 * 08:00 (the last on 29 October 13:00), ids 1–100, ages 20–89, alternating sex and department
 * (`Innere Medizin`, `Unfallchirurgie`), urgency 1, 2, 3 in turn, every tenth with an emergency
 * physician and every 25th a work accident.
 */
final class HandMadeAllocations
{
    public const array DEPARTMENTS = ['Innere Medizin', 'Unfallchirurgie'];

    /**
     * @return list<AllocationSnapshot>
     */
    public static function october2026(int $firstId = 1): array
    {
        $allocations = [];
        for ($i = 0; $i < 100; ++$i) {
            $allocations[] = new AllocationSnapshot(
                id: $firstId + $i,
                arrivalAt: new \DateTimeImmutable('2026-10-01 08:00:00')->modify(sprintf('+%d hours', 7 * $i)),
                age: 20 + $i % 70,
                gender: ['M', 'F'][$i % 2],
                urgency: 1 + $i % 3,
                isWithPhysician: 0 === $i % 10,
                isWorkAccident: 0 === $i % 25,
                departmentName: self::DEPARTMENTS[$i % 2],
            );
        }

        return $allocations;
    }
}
