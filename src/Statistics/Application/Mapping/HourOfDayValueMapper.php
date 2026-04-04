<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

use Symfony\Contracts\Translation\TranslatorInterface;

/** Hour 0–23 from created_hour (G format). */
final readonly class HourOfDayValueMapper implements ValueMapper
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function label(?int $value): string
    {
        if (null === $value || $value < 0 || $value > 23) {
            return $this->translator->trans('statistics.distribution.hour.unknown');
        }

        return $this->translator->trans('statistics.distribution.hour.slot', [
            'hour' => sprintf('%02d', $value),
        ]);
    }
}
