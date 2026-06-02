<?php

declare(strict_types=1);

namespace App\Content\Application\Page\DTO;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;

final readonly class PageNavigationLink
{
    public function __construct(
        public PageKey $key,
        public Page $page,
        public string $url,
        public string $label,
    ) {
    }
}
