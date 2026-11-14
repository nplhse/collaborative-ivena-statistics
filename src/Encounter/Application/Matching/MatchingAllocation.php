<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

/**
 * The fields of an IVENA allocation the matcher compares (ADR 030). Urgency and department are deliberately
 * absent; the indication (PZC) is read only for the plausibility bonus of pass 2 (matcher 1.2.0, ADR 033).
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
        /** Normalized IVENA indication code (PZC), null when not normalized. */
        public ?int $pzc = null,
    ) {
    }
}
