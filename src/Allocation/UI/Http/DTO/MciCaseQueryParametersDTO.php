<?php

namespace App\Allocation\UI\Http\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class MciCaseQueryParametersDTO
{
    public function __construct(
        #[Assert\GreaterThan(0)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,

        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $orderBy = 'desc',

        #[Assert\Choice(choices: ['createdAt', 'arrivalAt', 'mciTitle'])]
        public string $sortBy = 'createdAt',

        #[Assert\GreaterThan(0)]
        public ?int $importId = null,
    ) {
    }
}
