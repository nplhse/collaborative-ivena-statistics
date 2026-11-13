<?php

declare(strict_types=1);

namespace App\Encounter\UI\Twig;

use App\Encounter\Application\Catalog\MtsCatalog;
use App\Encounter\Application\Catalog\MtsCatalogEntry;
use App\Encounter\UI\Glossary\MtsNames;

/**
 * Display name of an MTS diagram code for pages outside the glossary, e.g. the encounter analytics
 * in Statistics (#1055): the English chart name in English, else the German catalog name; unknown
 * codes are shown as they are.
 *
 * @psalm-suppress UnusedClass Registered through the AsTwigFunction attribute.
 */
final readonly class MtsNameExtension
{
    public function __construct(
        private MtsCatalog $catalog,
        private MtsNames $names,
    ) {
    }

    #[\Twig\Attribute\AsTwigFunction(name: 'encounter_mts_diagram_name')]
    public function diagramName(string $code): string
    {
        $entry = $this->catalog->diagram($code);

        return $entry instanceof MtsCatalogEntry ? $this->names->diagram($entry)->name : $code;
    }
}
