<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

final readonly class SummarizedReportsIndexCardViewModel
{
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $descriptionKey,
        public string $url,
    ) {
    }
}
