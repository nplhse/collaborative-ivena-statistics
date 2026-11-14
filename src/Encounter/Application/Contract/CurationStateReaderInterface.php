<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Curation\CurationState;

/**
 * Reads the MTS catalog and the plausibility problem areas for the curation package (#1198).
 */
interface CurationStateReaderInterface
{
    public function state(): CurationState;
}
