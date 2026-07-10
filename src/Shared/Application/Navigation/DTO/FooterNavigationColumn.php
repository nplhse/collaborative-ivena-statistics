<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\DTO;

final readonly class FooterNavigationColumn
{
    /**
     * @param list<FooterNavigationLink> $links
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public array $links,
    ) {
    }
}
