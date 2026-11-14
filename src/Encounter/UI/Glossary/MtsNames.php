<?php

declare(strict_types=1);

namespace App\Encounter\UI\Glossary;

use App\Encounter\Application\Catalog\MtsCatalogEntry;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Display names of MTS diagrams and indicators (#1056, ADR 032). The German name of the catalog is the source.
 * In English, an entry shows its English name when the catalog has one (for the seeded diagrams: the well-known
 * English chart name); otherwise the German name.
 */
final readonly class MtsNames
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function diagram(MtsCatalogEntry $entry): MtsGlossaryRow
    {
        return $this->row($entry);
    }

    public function indicator(MtsCatalogEntry $entry): MtsGlossaryRow
    {
        return $this->row($entry);
    }

    private function row(MtsCatalogEntry $entry): MtsGlossaryRow
    {
        if (null !== $entry->englishName && $this->isEnglish()) {
            return new MtsGlossaryRow($entry->code, $entry->englishName, $entry->germanName);
        }

        return new MtsGlossaryRow($entry->code, $entry->germanName, null);
    }

    private function isEnglish(): bool
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';

        return str_starts_with($locale, 'en');
    }
}
