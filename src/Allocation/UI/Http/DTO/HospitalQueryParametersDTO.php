<?php

namespace App\Allocation\UI\Http\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class HospitalQueryParametersDTO
{
    public function __construct(
        #[Assert\GreaterThan(0)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,

        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $orderBy = 'asc',

        #[Assert\Choice(choices: ['id', 'name', 'dispatchArea', 'state', 'location', 'tier', 'size', 'lastChange'])]
        public string $sortBy = 'name',

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
