<?php

declare(strict_types=1);

namespace App\Storage\Application\Contract;

/**
 * The migrations this application version knows (ADR 038, "Restore").
 */
interface MigrationCatalogInterface
{
    /**
     * @return list<string> migration versions, e.g. "DoctrineMigrations\Version20261119220659"
     */
    public function available(): array;
}
