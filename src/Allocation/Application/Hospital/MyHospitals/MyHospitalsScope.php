<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\MyHospitals;

/**
 * Hospitals shown on My Hospitals: owned hospitals and hospitals with a grant that includes VIEW.
 */
final readonly class MyHospitalsScope
{
    /**
     * @param list<MyHospitalsEntry> $entries sorted by hospital name
     */
    public function __construct(
        public array $entries,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->entries;
    }

    public function count(): int
    {
        return \count($this->entries);
    }
}
