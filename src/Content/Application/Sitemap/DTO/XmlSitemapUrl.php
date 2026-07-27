<?php

declare(strict_types=1);

namespace App\Content\Application\Sitemap\DTO;

final readonly class XmlSitemapUrl
{
    public function __construct(
        public string $loc,
        public ?string $lastmod = null,
    ) {
    }
}
