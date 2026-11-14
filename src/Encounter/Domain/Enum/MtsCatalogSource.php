<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where an MTS catalog entry came from (ADR 032).
 *
 * `orbis_export` is the seed from the analysed ORBIS export, `mts_edition` the printed MTS edition, `manual` an entry
 * added by an administrator and `first_seen` an entry created automatically by the import (#1157).
 */
enum MtsCatalogSource: string implements TranslatableInterface
{
    case OrbisExport = 'orbis_export';
    case MtsEdition = 'mts_edition';
    case Manual = 'manual';
    case FirstSeen = 'first_seen';

    #[\Override]
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('encounter.mts_catalog.source.'.$this->value, [], 'encounter', $locale);
    }
}
