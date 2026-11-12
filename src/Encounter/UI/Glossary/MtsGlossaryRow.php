<?php

declare(strict_types=1);

namespace App\Encounter\UI\Glossary;

use App\Encounter\Application\Glossary\MtsCount;

/**
 * One diagram or indicator row of the MTS glossary. `germanName` is set when `name` is an English
 * name; `inCatalog` is false for indicator codes seen in data but not listed in the MTS catalog.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the glossary templates.
 */
final readonly class MtsGlossaryRow
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $germanName,
        public ?MtsCount $count = null,
        public bool $inCatalog = true,
    ) {
    }
}
