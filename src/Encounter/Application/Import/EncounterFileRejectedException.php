<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

use App\Encounter\Domain\Enum\EncounterFileRejection;

/**
 * The whole file is rejected (#1048). The message names the reason code only, never file content.
 */
final class EncounterFileRejectedException extends \RuntimeException
{
    public function __construct(public readonly EncounterFileRejection $reason)
    {
        parent::__construct(sprintf('Encounter file rejected: %s.', $reason->value));
    }
}
