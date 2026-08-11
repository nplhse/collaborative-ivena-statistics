<?php

declare(strict_types=1);

namespace App\Content\Application\Page\DTO;

final readonly class PageLocaleAlternateLink
{
    public function __construct(
        public string $locale,
        public string $label,
        public string $url,
        public string $path,
        public bool $current,
    ) {
    }
}
