<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\Twig;

use Cois\Plugin\EdVisit\Application\Catalog\MtsCatalog;
use Cois\Plugin\EdVisit\Application\Catalog\MtsCatalogEntry;
use Cois\Plugin\EdVisit\UI\Glossary\MtsNames;

/**
 * Display names of MTS diagram and indicator codes for pages outside the glossary, e.g. the encounter analytics
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

    /** Display name of an MTS indicator code, like the diagram name (#1178). */
    #[\Twig\Attribute\AsTwigFunction(name: 'encounter_mts_indicator_name')]
    public function indicatorName(string $code): string
    {
        $entry = $this->catalog->indicator($code);

        return $entry instanceof MtsCatalogEntry ? $this->names->indicator($entry)->name : $code;
    }
}
