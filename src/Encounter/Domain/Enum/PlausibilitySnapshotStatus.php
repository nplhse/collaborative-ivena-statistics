<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Life cycle of a plausibility snapshot (ADR 033): `draft` → `active` → `retired`. At most one snapshot is active;
 * active and retired snapshots never change.
 */
enum PlausibilitySnapshotStatus: string implements TranslatableInterface
{
    case Draft = 'draft';
    case Active = 'active';
    case Retired = 'retired';

    #[\Override]
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('encounter.plausibility.snapshot.status.'.$this->value, [], 'encounter', $locale);
    }
}
