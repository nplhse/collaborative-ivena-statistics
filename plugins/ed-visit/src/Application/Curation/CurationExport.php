<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Curation;

use Cois\Plugin\EdVisit\Application\Curation\Package\CurationPackage;

/**
 * A sealed package and what the export left out because it is not curated.
 */
final readonly class CurationExport
{
    /**
     * @param array<string, int> $omitted rows left out per kind of row (provisional entries, aliases, assignments
     *                                    and rules that name a provisional or merged entry)
     */
    public function __construct(
        public CurationPackage $package,
        public array $omitted,
    ) {
    }
}
