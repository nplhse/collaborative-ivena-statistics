<?php

declare(strict_types=1);

namespace App\Encounter\Application\Glossary;

/**
 * Hospitals whose encounters a user may see as counts (ADR 028): either all hospitals
 * (administrators) or an explicit list of hospital ids, possibly empty.
 */
final readonly class EncounterHospitalScope
{
    /**
     * @param list<int> $hospitalIds
     */
    private function __construct(
        public bool $allHospitals,
        public array $hospitalIds,
    ) {
    }

    public static function allHospitals(): self
    {
        return new self(true, []);
    }

    /**
     * @param list<int> $hospitalIds
     */
    public static function hospitals(array $hospitalIds): self
    {
        $hospitalIds = array_values(array_unique($hospitalIds));
        sort($hospitalIds);

        return new self(false, $hospitalIds);
    }

    public function isEmpty(): bool
    {
        return !$this->allHospitals && [] === $this->hospitalIds;
    }
}
