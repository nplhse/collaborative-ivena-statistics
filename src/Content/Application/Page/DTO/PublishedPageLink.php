<?php

declare(strict_types=1);

namespace App\Content\Application\Page\DTO;

final readonly class PublishedPageLink
{
    public function __construct(
        public string $url,
        public string $label,
    ) {
    }
}
