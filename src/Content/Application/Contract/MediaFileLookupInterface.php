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

    /** New URL of a media item that was moved from the old upload directory, by its old file name. */
    public function findMovedUrl(string $legacyFilename): ?string;

    /** File of a media item not yet moved, in the old upload directory, by its file name. */
    public function findLegacyFile(string $legacyFilename): ?DeliverableMediaFile;
}
