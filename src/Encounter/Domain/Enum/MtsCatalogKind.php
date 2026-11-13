<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The two lists of the MTS catalog (ADR 032): presentation diagrams and indicators. Codes are unique per kind.
 */
enum MtsCatalogKind: string implements TranslatableInterface
{
    case Diagram = 'diagram';
    case Indicator = 'indicator';

    #[\Override]
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('encounter.mts_catalog.kind.'.$this->value, [], 'encounter', $locale);
    }
}
