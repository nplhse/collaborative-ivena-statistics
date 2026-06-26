<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\DTO;

use App\Allocation\Domain\Enum\IndicationRawReviewWorklistSegment;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class IndicationRawReviewWorklistQueryDTO
{
    public function __construct(
        #[Assert\GreaterThan(0)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,

        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $orderBy = 'asc',

        #[Assert\Choice(choices: ['id', 'name', 'code', 'createdAt', 'occurrence', 'reviewedAt', 'firstMatchedAt'])]
        public string $sortBy = 'createdAt',

        public ?string $search = null,

        public IndicationRawReviewWorklistSegment $segment = IndicationRawReviewWorklistSegment::Open,
    ) {
    }
}
