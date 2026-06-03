<?php

declare(strict_types=1);

namespace App\Statistics\Application\Cohort;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class HospitalCohortLabelResolver
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function label(HospitalCohortKey $key, ?string $locale = null): string
    {
        return $this->translator->trans(
            'stats.filter.cohort.label',
            [
                'location' => $this->translator->trans(
                    'hospital.location.'.$key->location->value,
                    [],
                    null,
                    $locale,
                ),
                'tier' => $this->translator->trans(
                    'hospital.tier.'.$key->tier->value,
                    [],
                    null,
                    $locale,
                ),
            ],
            null,
            $locale,
        );
    }
}
