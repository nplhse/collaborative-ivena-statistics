<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

/**
 * Names of PZC codes from the normalized indications of the Allocation context, for the review pages of the
 * plausibility table. Codes without a stored indication have no name.
 */
interface PzcNameReaderInterface
{
    /**
     * @return array<int, string> name by PZC
     */
    public function names(): array;
}
