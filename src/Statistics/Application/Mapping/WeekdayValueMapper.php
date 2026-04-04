<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

use Symfony\Contracts\Translation\TranslatorInterface;

/** ISO weekday 1 = Monday … 7 = Sunday (see PHP {@see \DateTimeInterface::format} `N`). */
final readonly class WeekdayValueMapper implements ValueMapper
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function label(?int $value): string
    {
        if (null === $value || $value < 1 || $value > 7) {
            return $this->translator->trans('statistics.distribution.weekday.unknown');
        }

        return $this->translator->trans('statistics.distribution.weekday.n'.$value);
    }
}
