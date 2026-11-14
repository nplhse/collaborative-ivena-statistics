<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where the cells of a plausibility snapshot came from (ADR 033): `seed` is built from the curated problem areas,
 * `learned` by the learning job (#1155).
 */
enum PlausibilitySnapshotOrigin: string implements TranslatableInterface
{
    case Seed = 'seed';
    case Learned = 'learned';

    #[\Override]
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('encounter.plausibility.snapshot.origin.'.$this->value, [], 'encounter', $locale);
    }
}
