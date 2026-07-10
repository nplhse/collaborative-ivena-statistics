<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\DTO;

final readonly class FooterNavigationLink
{
    public function __construct(
        public string $url,
        public string $label,
    ) {
    }
}
