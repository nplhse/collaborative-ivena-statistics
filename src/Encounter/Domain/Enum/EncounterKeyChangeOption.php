<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * What happens to the hospital's existing encounter data when the pseudonym key changes (ADR 026).
 */
enum EncounterKeyChangeOption: string
{
    /** All encounter imports of earlier generations are deleted with their visits, links, review decisions and files. */
    case Delete = 'delete';

    /** Existing visits stay, distinguishable by their key generation; their links remain effective. */
    case Keep = 'keep';
}
