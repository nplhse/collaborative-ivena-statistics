<?php

declare(strict_types=1);

namespace App\Encounter\UI\Glossary;

use App\Encounter\Application\Catalog\MtsCatalogEntry;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Display names of MTS diagrams and indicators (#1056). The German name of the catalog is the source.
 * In English, a diagram shows its well-known English chart name when the `encounter` domain defines
 * `encounter.mts.diagram_name.<code>` for `en`; otherwise, and for all indicators, the German name.
 * These keys exist in English only on purpose: the German name comes from the catalog, not from a
 * translation file.
 */
final readonly class MtsNames
{
    private const string DOMAIN = 'encounter';

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
    ) {
    }

    public function diagram(MtsCatalogEntry $entry): MtsGlossaryRow
    {
        $key = 'encounter.mts.diagram_name.'.$entry->code;
        if ($this->isEnglish() && $this->translator instanceof TranslatorBagInterface && $this->translator->getCatalogue('en')->defines($key, self::DOMAIN)) {
            return new MtsGlossaryRow($entry->code, $this->translator->trans($key, [], self::DOMAIN, 'en'), $entry->germanName);
        }

        return new MtsGlossaryRow($entry->code, $entry->germanName, null);
    }

    public function indicator(MtsCatalogEntry $entry): MtsGlossaryRow
    {
        return new MtsGlossaryRow($entry->code, $entry->germanName, null);
    }

    private function isEnglish(): bool
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';

        return str_starts_with($locale, 'en');
    }
}
