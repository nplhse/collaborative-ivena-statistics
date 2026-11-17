<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Assignment;

/**
 * Base data of a treatment case that a schema provides when the core creates the encounter of an IVENA
 * allocation for one of its records (#1300, ADR 037). `$sex` is a code of the exchange format (`F`, `M`, …).
 */
final readonly class EncounterBaseData
{
    /**
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it.
     */
    public function __construct(
        public int $hospitalId,
        public int $keyGeneration,
        public string $caseKey,
        public \DateTimeImmutable $anchorAt,
        public int $age,
        public string $sex,
    ) {
    }
}
