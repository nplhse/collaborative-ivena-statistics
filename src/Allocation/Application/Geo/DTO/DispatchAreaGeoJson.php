<?php

declare(strict_types=1);

namespace App\Allocation\Application\Geo\DTO;

/**
 * @psalm-immutable
 */
final readonly class DispatchAreaGeoJson
{
    public function __construct(
        public string $content,
        public string $etag,
    ) {
    }
}
