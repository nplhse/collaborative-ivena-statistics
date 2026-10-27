<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Restore;

final class ReferenceCatalogRestoreBlockedException extends \RuntimeException
{
    /**
     * @param list<RestoreBlocker> $blockers
     */
    public function __construct(
        public readonly array $blockers,
    ) {
        parent::__construct(sprintf('Restore blocked: %d table(s) refer to the catalog.', \count($blockers)));
    }
}
