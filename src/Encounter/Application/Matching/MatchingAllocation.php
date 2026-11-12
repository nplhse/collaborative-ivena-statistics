<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

/**
 * The fields of an IVENA allocation the matcher compares (ADR 029). Urgency, department and indication
 * are deliberately absent.
 */
final readonly class MatchingAllocation
{
    /**
     * @param string $sex IVENA gender code (`M`, `F`, `X`)
     */
    public function __construct(
        public int $id,
        public \DateTimeImmutable $arrivalAt,
        public string $sex,
        public int $age,
        public bool $withPhysician,
        public bool $workAccident,
    ) {
    }
}
