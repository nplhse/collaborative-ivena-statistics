<?php

namespace App\DataTransferObjects;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AllocationQueryParametersDTO
{
    public function __construct(
        #[Assert\GreaterThan(0)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 50,

        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $orderBy = 'desc',

        #[Assert\Choice(choices: ['age', 'arrivalAt'])]
        public string $sortBy = 'arrivalAt',

        #[Assert\GreaterThan(0)]
        public ?int $importId = null,

        public ?string $tier = null,

        public ?string $location = null,

        public ?string $size = null,

        public ?string $urgency = null,

        public ?int $dispatchArea = null,

        public ?int $state = null,

        public ?int $requiresResus = null,

        public ?int $requiresCathlab = null,

        public ?int $indication = null,
    ) {
    }
}
