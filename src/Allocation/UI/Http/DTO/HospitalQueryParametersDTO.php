<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\DTO;

use App\Allocation\UI\Twig\HospitalListColumns;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class HospitalQueryParametersDTO
{
    public function __construct(
        #[Assert\GreaterThan(0)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,

        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $orderBy = HospitalListColumns::DEFAULT_ORDER_BY,

        #[Assert\Choice(choices: HospitalListColumns::SORT_KEYS)]
        public string $sortBy = HospitalListColumns::DEFAULT_SORT_BY,

        public ?string $search = null,

        public ?string $tier = null,

        public ?string $location = null,

        public ?string $size = null,

        public ?int $dispatchArea = null,

        public ?int $state = null,

        public ?string $participating = null,
    ) {
    }
}
