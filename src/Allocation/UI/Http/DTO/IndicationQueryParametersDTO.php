<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\DTO;

use App\Allocation\UI\Twig\IndicationNormalizedListColumns;
use App\Allocation\UI\Twig\IndicationRawListColumns;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class IndicationQueryParametersDTO
{
    public function __construct(
        #[Assert\GreaterThan(0)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,

        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $orderBy = 'asc',

        // Both tabs share this DTO; a key of the other tab falls back to the code.
        #[Assert\Choice(choices: [...IndicationNormalizedListColumns::SORT_KEYS, ...IndicationRawListColumns::SORT_KEYS])]
        public string $sortBy = 'code',

        public ?string $search = null,

        #[Assert\Choice(choices: ['normalized', 'raw'])]
        public string $type = 'normalized',
    ) {
    }
}
