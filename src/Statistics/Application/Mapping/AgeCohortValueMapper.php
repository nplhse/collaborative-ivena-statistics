<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

use App\Statistics\Application\Panel\Distribution\AgeCohortBucketExpression;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AgeCohortValueMapper implements ValueMapper
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function label(?int $value): string
    {
        $key = match ($value) {
            AgeCohortBucketExpression::UNKNOWN => 'statistics.distribution.age.unknown',
            AgeCohortBucketExpression::UNDER_18 => 'statistics.distribution.age.under_18',
            1 => 'statistics.distribution.age.18_29',
            2 => 'statistics.distribution.age.30_39',
            3 => 'statistics.distribution.age.40_49',
            4 => 'statistics.distribution.age.50_59',
            5 => 'statistics.distribution.age.60_69',
            6 => 'statistics.distribution.age.70_79',
            7 => 'statistics.distribution.age.80_89',
            8 => 'statistics.distribution.age.90_99',
            default => 'statistics.distribution.unknown_code',
        };

        return $this->translator->trans($key);
    }
}
