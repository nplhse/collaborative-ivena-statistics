<?php

declare(strict_types=1);

namespace App\Import\UI\Http\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ImportRejectQueryParametersDTO
{
    public function __construct(
        #[Assert\GreaterThan(0)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 200)]
        public int $limit = 50,

        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $orderBy = 'desc',

        #[Assert\Choice(choices: ['createdAt', 'importId', 'hospital'])]
        public string $sortBy = 'createdAt',

        #[Assert\GreaterThan(0)]
        public ?int $importId = null,

        #[Assert\GreaterThan(0)]
        public ?int $hospitalId = null,

        #[Assert\Length(min: 2, max: 200)]
        public ?string $search = null,
    ) {
    }
}
