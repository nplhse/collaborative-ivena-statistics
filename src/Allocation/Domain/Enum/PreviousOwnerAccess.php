<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

/**
 * What happens to the previous owner when an approved request transfers the ownership of a hospital (#975). The
 * reviewer chooses it explicitly for every transfer.
 */
enum PreviousOwnerAccess: string
{
    /** The previous owner keeps an access grant with every permission. */
    case KeepFullAccess = 'keep_full_access';

    /** The previous owner loses access to the hospital, unless they hold it otherwise. */
    case RemoveAccess = 'remove_access';
}
