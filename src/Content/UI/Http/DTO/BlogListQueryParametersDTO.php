<?php

declare(strict_types=1);

namespace App\Content\UI\Http\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class BlogListQueryParametersDTO
{
    public function __construct(
        #[Assert\GreaterThan(0)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 10)]
        public int $limit = 10,
    ) {
    }
}
