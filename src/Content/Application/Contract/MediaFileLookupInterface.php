<?php

declare(strict_types=1);

namespace App\Content\Application\Contract;

use App\Content\Application\Media\DeliverableMediaFile;

/**
 * Finds the file behind a media URL for the delivery route (ADR 039). Only files of existing public media items are
 * found; restricted media are not delivered until #580 decides who may see them.
 */
interface MediaFileLookupInterface
{
    /** File of a stored media item, by its storage key. */
    public function findStoredFile(string $storageKey): ?DeliverableMediaFile;
}
