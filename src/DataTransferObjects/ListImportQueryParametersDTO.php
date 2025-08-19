<?php

namespace App\DataTransferObjects;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ListImportQueryParametersDTO
{
    public function __construct(
        #[Assert\GreaterThan(0)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,

        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $orderBy = 'asc',

        #[Assert\Choice(choices: ['id', 'type', 'name', 'status', 'hospital', 'lastChange'])]
        public string $sortBy = 'name',

        public ?string $search = null,
    ) {
    }
}
