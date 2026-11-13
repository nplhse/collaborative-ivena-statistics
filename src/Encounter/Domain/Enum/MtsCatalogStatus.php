<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Review state of an MTS catalog entry (ADR 032).
 *
 * `curated` (reviewed by a person) and `provisional` (created automatically, not reviewed yet) are active: the entry
 * resolves to itself. `merged` marks an entry that became an alias of another entry; it resolves through its alias.
 */
enum MtsCatalogStatus: string implements TranslatableInterface
{
    case Curated = 'curated';
    case Provisional = 'provisional';
    case Merged = 'merged';

    public function isActive(): bool
    {
        return self::Merged !== $this;
    }

    #[\Override]
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('encounter.mts_catalog.status.'.$this->value, [], 'encounter', $locale);
    }
}
