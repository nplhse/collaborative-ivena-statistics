<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Display;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Link from an encounter import to the records it brought in, as an action on the import detail page (#1413).
 * A schema returns a link for its own imports only; the first link wins. Imports of a schema without a provider
 * get no action. The core shows the link only with `VIEW_CASES` on the import's hospital; the linked page checks
 * again.
 */
#[AutoconfigureTag(self::TAG)]
interface ImportRecordsLinkProviderInterface
{
    public const string TAG = 'encounter.import_records_link';

    /**
     * @param string $schema           the schema the import was uploaded for
     * @param string $hospitalPublicId public id of the import's hospital
     */
    public function linkForImport(string $schema, int $importId, string $hospitalPublicId): ?ImportRecordsLink;
}
