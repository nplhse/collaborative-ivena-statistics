<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Source of the points of a plausibility cell (ADR 033): `curated` from a problem area, `learned` by the learning job.
 */
enum PlausibilityCellSource: string implements TranslatableInterface
{
    case Curated = 'curated';
    case Learned = 'learned';

    #[\Override]
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('encounter.plausibility.source.'.$this->value, [], 'encounter', $locale);
    }
}
